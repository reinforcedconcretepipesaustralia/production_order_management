<?php

namespace App\Services\DeliveryConfirmation;

use App\Exceptions\DeliveryConfirmationException;
use App\Models\DeliveryConfirmationSend;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Throwable;

class DeliveryConfirmationSendService
{
    public function __construct(
        private OpenOrderCandidateFinder $finder,
        private DeliveryConfirmationMailFactory $mailFactory,
        private MicrosoftGraphDeliveryMailClient $mailClient,
        private DeliveryReminderMailboxResolver $mailboxes,
    ) {}

    /**
     * @return array{sent: int, failed: int, skipped: int}
     */
    public function sendDue(?CarbonImmutable $today = null, bool $force = false): array
    {
        if (! $force && ! config('delivery-reminders.enabled')) {
            return ['sent' => 0, 'failed' => 0, 'skipped' => 0];
        }

        $counts = ['sent' => 0, 'failed' => 0, 'skipped' => 0];

        foreach ($this->finder->unsentCandidates($today) as $group) {
            $senderMailbox = $this->mailboxes->forEntity($group->entity);

            if ($senderMailbox === null) {
                DeliveryConfirmationSend::query()->create([
                    'tracking_token' => 'DC-'.Str::upper(Str::random(16)),
                    'entity' => $group->entity,
                    'reference_nbr' => $group->referenceNbr,
                    'reference_duplicate' => $group->referenceDuplicate,
                    'customer_code' => $group->customerCode,
                    'customer_name' => $group->customerName,
                    'recipient_email' => $group->recipientEmail,
                    'sender_mailbox' => null,
                    'stage' => $group->stage,
                    'target_delivery_date' => $group->targetDeliveryDate->toDateString(),
                    'line_count' => $group->lineCount(),
                    'total_outstanding_qty' => $group->totalOutstandingQty,
                    'status' => 'skipped',
                    'failure_category' => 'mailbox-unmapped',
                    'failure_message' => 'No shared sales mailbox is mapped for this entity.',
                ]);
                $counts['skipped']++;

                continue;
            }

            $send = DeliveryConfirmationSend::query()->create([
                'tracking_token' => 'DC-'.Str::upper(Str::random(16)),
                'entity' => $group->entity,
                'reference_nbr' => $group->referenceNbr,
                'reference_duplicate' => $group->referenceDuplicate,
                'customer_code' => $group->customerCode,
                'customer_name' => $group->customerName,
                'recipient_email' => $group->recipientEmail,
                'sender_mailbox' => $senderMailbox,
                'stage' => $group->stage,
                'target_delivery_date' => $group->targetDeliveryDate->toDateString(),
                'line_count' => $group->lineCount(),
                'total_outstanding_qty' => $group->totalOutstandingQty,
                'status' => 'pending',
            ]);

            try {
                $message = $this->mailFactory->make($group, $send);
                $providerId = $this->mailClient->send($message, $senderMailbox);

                $send->update([
                    'status' => 'sent',
                    'sent_at' => now(),
                    'provider_message_id' => $providerId,
                ]);
                $counts['sent']++;
            } catch (DeliveryConfirmationException $exception) {
                $send->update([
                    'status' => 'failed',
                    'failure_category' => $exception->category,
                    'failure_message' => mb_substr($exception->getMessage(), 0, 500),
                ]);
                $counts['failed']++;
            } catch (Throwable) {
                $send->update([
                    'status' => 'failed',
                    'failure_category' => 'unexpected',
                    'failure_message' => 'An unexpected error prevented the delivery reminder from sending.',
                ]);
                $counts['failed']++;
            }
        }

        return $counts;
    }

    /**
     * Record baseline rows for every current candidate so enabling the job is quiet.
     *
     * @return array{baselined: int, skipped: int}
     */
    public function markSeen(?CarbonImmutable $today = null): array
    {
        $counts = ['baselined' => 0, 'skipped' => 0];

        foreach ($this->finder->unsentCandidates($today) as $group) {
            DeliveryConfirmationSend::query()->create([
                'tracking_token' => 'DC-'.Str::upper(Str::random(16)),
                'entity' => $group->entity,
                'reference_nbr' => $group->referenceNbr,
                'reference_duplicate' => $group->referenceDuplicate,
                'customer_code' => $group->customerCode,
                'customer_name' => $group->customerName,
                'recipient_email' => $group->recipientEmail,
                'sender_mailbox' => $this->mailboxes->forEntity($group->entity),
                'stage' => $group->stage,
                'target_delivery_date' => $group->targetDeliveryDate->toDateString(),
                'line_count' => $group->lineCount(),
                'total_outstanding_qty' => $group->totalOutstandingQty,
                'status' => 'baseline',
            ]);
            $counts['baselined']++;
        }

        return $counts;
    }
}
