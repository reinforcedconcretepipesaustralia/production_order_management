<?php

namespace App\Services\Dispatch;

use App\Jobs\SendDispatchNotification;
use App\Models\DispatchLoad;
use App\Models\DispatchNotificationAttempt;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class DispatchNotificationManager
{
    /**
     * @param  list<string>  $recipients
     *
     * @throws ValidationException
     */
    public function queue(
        DispatchLoad $load,
        array $recipients,
        User $actor,
    ): DispatchNotificationAttempt {
        $recipients = collect($recipients)
            ->map(fn (string $recipient): string => mb_strtolower(trim($recipient)))
            ->filter()
            ->unique()
            ->values()
            ->all();

        Validator::make(
            ['recipients' => $recipients],
            [
                'recipients' => ['required', 'array', 'max:20'],
                'recipients.*' => ['required', 'email:rfc', 'max:254'],
            ],
        )->validate();

        $this->ensureLoadIsReady($load);

        [$attempt, $shouldDispatch] = DB::transaction(function () use ($load, $recipients, $actor): array {
            $idempotencyKey = $this->idempotencyKey($load);
            $attempt = DispatchNotificationAttempt::query()
                ->createOrFirst(
                    ['idempotency_key' => $idempotencyKey],
                    [
                        'dispatch_load_id' => $load->id,
                        'provider' => 'microsoft-graph',
                        'event_type' => 'dispatch-load-ready',
                        'recipients' => $recipients,
                        'status' => 'queued',
                        'actor_user_id' => $actor->id,
                    ],
                );

            if ($attempt->wasRecentlyCreated) {
                return [$attempt, true];
            }

            $attempt = DispatchNotificationAttempt::query()
                ->lockForUpdate()
                ->findOrFail($attempt->id);

            if ($attempt->status !== 'failed') {
                return [$attempt, false];
            }

            $attempt->update([
                'recipients' => $recipients,
                'status' => 'queued',
                'actor_user_id' => $actor->id,
                'failure_category' => null,
                'failure_message' => null,
            ]);

            return [$attempt->refresh(), true];
        });

        if ($shouldDispatch) {
            SendDispatchNotification::dispatch($attempt->id)->afterCommit();
        }

        return $attempt;
    }

    public function idempotencyKey(DispatchLoad $load): string
    {
        return hash('sha256', "dispatch-load:{$load->id}:dispatch-load-ready");
    }

    private function ensureLoadIsReady(DispatchLoad $load): void
    {
        $load->loadCount('items');

        if ($load->items_count < 1 || (float) $load->current_weight_tonnes <= 0) {
            throw new \InvalidArgumentException('Add at least one load item before sending a dispatch notification.');
        }

        if ((float) $load->capacity_tonnes <= 0) {
            throw new \InvalidArgumentException('The dispatch load must have a valid capacity.');
        }

        if ($load->dispatch_date->isBefore(today())) {
            throw new \InvalidArgumentException('A notification cannot be sent for a past dispatch date.');
        }

        if (in_array($load->status, ['cancelled', 'dispatched'], true)) {
            throw new \InvalidArgumentException('This dispatch load is not in a notifiable state.');
        }
    }
}
