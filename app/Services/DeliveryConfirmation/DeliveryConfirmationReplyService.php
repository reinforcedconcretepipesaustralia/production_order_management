<?php

namespace App\Services\DeliveryConfirmation;

use App\Exceptions\DeliveryConfirmationException;
use App\Models\DeliveryConfirmationLineResponse;
use App\Models\DeliveryConfirmationSend;
use App\Models\Rcpa\VwOPENORDERSView;
use App\Services\Sync\TenciaSource;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Throwable;

class DeliveryConfirmationReplyService
{
    private const LAST_POLL_CACHE_KEY = 'delivery_confirmations.last_reply_poll_at';

    public function __construct(
        private MicrosoftGraphDeliveryMailClient $mailClient,
        private DeliveryDateReplyParser $parser,
        private TenciaDeliveryDateUpdater $updater,
        private TenciaSource $tencia,
        private DeliveryReminderMailboxResolver $mailboxes,
    ) {}

    /**
     * @return array{processed: int, unresolved: int, written: int, confirmed: int, pending_approval: int}
     */
    public function processInbox(?CarbonImmutable $today = null): array
    {
        $today = ($today ?? CarbonImmutable::today('Australia/Melbourne'))->startOfDay();
        $since = Cache::get(self::LAST_POLL_CACHE_KEY);
        $sinceIso = $since
            ? CarbonImmutable::parse($since)->toIso8601String()
            : now()->subDay()->toIso8601String();

        $counts = [
            'processed' => 0,
            'unresolved' => 0,
            'written' => 0,
            'confirmed' => 0,
            'pending_approval' => 0,
        ];

        $latestReceived = $since ? CarbonImmutable::parse($since) : null;

        foreach ($this->mailboxes->uniqueMailboxes() as $mailbox) {
            foreach ($this->mailClient->inboxMessagesSince($mailbox, $sinceIso) as $message) {
                if ($message->receivedAt !== '') {
                    $received = CarbonImmutable::parse($message->receivedAt);
                    $latestReceived = $latestReceived === null || $received->gt($latestReceived)
                        ? $received
                        : $latestReceived;
                }

                $send = $this->matchSend($message);
                if ($send === null) {
                    continue;
                }

                if ($send->reply_provider_message_id === $message->id) {
                    continue;
                }

                $result = $this->applyReply($send, $message, $today);
                $counts['processed']++;
                $counts['unresolved'] += $result['unresolved'];
                $counts['written'] += $result['written'];
                $counts['confirmed'] += $result['confirmed'];
                $counts['pending_approval'] += $result['pending_approval'];
            }
        }

        if ($latestReceived !== null) {
            Cache::put(self::LAST_POLL_CACHE_KEY, $latestReceived->toIso8601String());
        }

        return $counts;
    }

    /**
     * @return array{unresolved: int, written: int, confirmed: int, pending_approval: int}
     */
    public function applyReply(
        DeliveryConfirmationSend $send,
        GraphMailboxMessage $message,
        ?CarbonImmutable $today = null,
    ): array {
        $today = ($today ?? CarbonImmutable::today('Australia/Melbourne'))->startOfDay();
        $lines = $this->currentOpenLines($send);
        $knownIds = array_map(fn (array $line): int => $line['line_unique_id'], $lines);
        $parsed = $this->parser->parse($message->bodyText, $knownIds);

        $send->update([
            'reply_received_at' => $message->receivedAt !== '' ? CarbonImmutable::parse($message->receivedAt) : now(),
            'reply_provider_message_id' => $message->id,
        ]);

        if ($parsed['status'] !== 'ok') {
            $send->update([
                'reply_outcome' => 'unresolved',
                'reply_outcome_message' => mb_substr((string) ($parsed['message'] ?? 'Ambiguous reply.'), 0, 500),
            ]);

            return ['unresolved' => 1, 'written' => 0, 'confirmed' => 0, 'pending_approval' => 0];
        }

        $linesById = [];
        foreach ($lines as $line) {
            $linesById[$line['line_unique_id']] = $line;
        }

        $counts = ['unresolved' => 0, 'written' => 0, 'confirmed' => 0, 'pending_approval' => 0];

        foreach ($parsed['instructions'] as $instruction) {
            $line = $linesById[$instruction['line_unique_id']];
            $currentDate = CarbonImmutable::parse($line['delivery_date'])->startOfDay();

            if ($instruction['action'] === 'confirm') {
                $this->storeLineResponse($send, $line, [
                    'action' => 'confirm',
                    'requested_delivery_date' => null,
                    'outcome' => 'confirmed',
                    'outcome_message' => 'Customer confirmed the current delivery date.',
                    'needs_rep_approval' => false,
                ]);
                $counts['confirmed']++;

                continue;
            }

            /** @var CarbonImmutable $requested */
            $requested = $instruction['date'];

            if ($requested->equalTo($currentDate)) {
                $this->storeLineResponse($send, $line, [
                    'action' => 'confirm',
                    'requested_delivery_date' => $requested->toDateString(),
                    'outcome' => 'confirmed',
                    'outcome_message' => 'Requested date matched the current delivery date.',
                    'needs_rep_approval' => false,
                ]);
                $counts['confirmed']++;

                continue;
            }

            $daysToDelivery = (int) $today->diffInDays($currentDate, false);
            $isInsideTwoWeekWindow = $daysToDelivery >= 0 && $daysToDelivery <= 14;
            $isEarlier = $requested->lt($currentDate);

            if ($isInsideTwoWeekWindow && $isEarlier) {
                $this->storeLineResponse($send, $line, [
                    'action' => 'amend',
                    'requested_delivery_date' => $requested->toDateString(),
                    'outcome' => 'pending_rep_approval',
                    'outcome_message' => 'Earlier date requested inside the two-week window; held for sales rep approval.',
                    'needs_rep_approval' => true,
                ]);
                $counts['pending_approval']++;

                continue;
            }

            try {
                $write = $this->updater->updateLine(
                    entity: $send->entity,
                    referenceNbr: $send->reference_nbr,
                    referenceDuplicate: (int) $send->reference_duplicate,
                    lineUniqueId: (int) $line['line_unique_id'],
                    newDate: $requested,
                );

                $this->storeLineResponse($send, $line, [
                    'action' => 'amend',
                    'requested_delivery_date' => $requested->toDateString(),
                    'outcome' => 'written',
                    'outcome_message' => sprintf(
                        'Updated SOR_LINES.DELIVERY_DATE. SOR_MASTER.DELIVERY_DATE set to earliest open line date %s.',
                        $write['header_date'],
                    ),
                    'needs_rep_approval' => false,
                    'tencia_updated_at' => now(),
                ]);
                $counts['written']++;
            } catch (DeliveryConfirmationException $exception) {
                $this->storeLineResponse($send, $line, [
                    'action' => 'amend',
                    'requested_delivery_date' => $requested->toDateString(),
                    'outcome' => 'failed',
                    'outcome_message' => mb_substr($exception->getMessage(), 0, 500),
                    'needs_rep_approval' => false,
                ]);
                $counts['unresolved']++;
            } catch (Throwable) {
                $this->storeLineResponse($send, $line, [
                    'action' => 'amend',
                    'requested_delivery_date' => $requested->toDateString(),
                    'outcome' => 'failed',
                    'outcome_message' => 'An unexpected error prevented the Tencia date update.',
                    'needs_rep_approval' => false,
                ]);
                $counts['unresolved']++;
            }
        }

        $send->update([
            'reply_outcome' => $counts['unresolved'] > 0 && ($counts['written'] + $counts['confirmed'] + $counts['pending_approval']) === 0
                ? 'unresolved'
                : 'processed',
            'reply_outcome_message' => sprintf(
                'confirmed=%d written=%d pending_approval=%d failed=%d',
                $counts['confirmed'],
                $counts['written'],
                $counts['pending_approval'],
                $counts['unresolved'],
            ),
        ]);

        return $counts;
    }

    private function matchSend(GraphMailboxMessage $message): ?DeliveryConfirmationSend
    {
        if (preg_match('/\[(DC-[A-Z0-9]+)\]/', $message->subject.' '.$message->bodyText, $match) !== 1) {
            return null;
        }

        return DeliveryConfirmationSend::query()
            ->where('tracking_token', $match[1])
            ->whereIn('status', ['sent', 'baseline'])
            ->first();
    }

    /**
     * @return list<array{
     *     line_unique_id: int,
     *     stock_code: string,
     *     description: ?string,
     *     outstanding_qty: float,
     *     delivery_date: string
     * }>
     */
    private function currentOpenLines(DeliveryConfirmationSend $send): array
    {
        $query = $this->tencia->view('vwOPEN_ORDERS')
            ->where('ENTITY', $send->entity)
            ->where('REFERENCE_NBR', $send->reference_nbr)
            ->where('BACKORDER_QTY', '>', 0)
            ->orderBy('LINE_DELIVERY_DATE');

        VwOPENORDERSView::excludeCancelled($query);

        return $query
            ->get([
                'LINE_UNIQUE_ID',
                'STOCK_CODE',
                'BACKORDER_QTY',
                'LINE_DELIVERY_DATE',
            ])
            ->map(fn (object $line): array => [
                'line_unique_id' => (int) $line->LINE_UNIQUE_ID,
                'stock_code' => (string) $line->STOCK_CODE,
                'description' => null,
                'outstanding_qty' => (float) $line->BACKORDER_QTY,
                'delivery_date' => CarbonImmutable::parse($line->LINE_DELIVERY_DATE)->toDateString(),
            ])
            ->all();
    }

    /**
     * @param  array{
     *     line_unique_id: int,
     *     stock_code: string,
     *     description: ?string,
     *     outstanding_qty: float,
     *     delivery_date: string
     * }  $line
     * @param  array{
     *     action: string,
     *     requested_delivery_date: ?string,
     *     outcome: string,
     *     outcome_message: string,
     *     needs_rep_approval: bool,
     *     tencia_updated_at?: mixed
     * }  $attributes
     */
    private function storeLineResponse(DeliveryConfirmationSend $send, array $line, array $attributes): void
    {
        DeliveryConfirmationLineResponse::query()->updateOrCreate(
            [
                'delivery_confirmation_send_id' => $send->id,
                'line_unique_id' => $line['line_unique_id'],
            ],
            [
                'stock_code' => $line['stock_code'],
                'description' => $line['description'],
                'outstanding_qty' => $line['outstanding_qty'],
                'current_delivery_date' => $line['delivery_date'],
                'requested_delivery_date' => $attributes['requested_delivery_date'],
                'action' => $attributes['action'],
                'outcome' => $attributes['outcome'],
                'outcome_message' => $attributes['outcome_message'],
                'needs_rep_approval' => $attributes['needs_rep_approval'],
                'tencia_updated_at' => $attributes['tencia_updated_at'] ?? null,
            ],
        );
    }
}
