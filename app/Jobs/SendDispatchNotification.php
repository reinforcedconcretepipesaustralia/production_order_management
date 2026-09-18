<?php

namespace App\Jobs;

use App\Contracts\DispatchNotificationSender;
use App\Exceptions\DispatchNotificationException;
use App\Models\DispatchNotificationAttempt;
use App\Services\Dispatch\DispatchNotificationFactory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

class SendDispatchNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public int $timeout = 30;

    public function __construct(
        public readonly int $attemptId,
    ) {}

    public function handle(
        DispatchNotificationSender $sender,
        DispatchNotificationFactory $notificationFactory,
    ): void {
        $attempt = DB::transaction(function (): ?DispatchNotificationAttempt {
            $attempt = DispatchNotificationAttempt::query()
                ->with('dispatchLoad')
                ->lockForUpdate()
                ->find($this->attemptId);

            if ($attempt === null || $attempt->status === 'sent') {
                return null;
            }

            if (
                $attempt->status === 'sending'
                && $attempt->attempted_at?->isAfter(now()->subMinutes(5))
            ) {
                return null;
            }

            $attempt->update([
                'status' => 'sending',
                'attempt_count' => $attempt->attempt_count + 1,
                'attempted_at' => now(),
                'failure_category' => null,
                'failure_message' => null,
            ]);

            return $attempt->refresh()->load('dispatchLoad');
        });

        if ($attempt === null) {
            return;
        }

        try {
            $result = $sender->send(
                $notificationFactory->make($attempt->dispatchLoad, $attempt),
            );

            DB::transaction(function () use ($attempt, $result): void {
                $attempt->update([
                    'status' => 'sent',
                    'sent_at' => now(),
                    'provider_request_id' => $result->providerRequestId,
                    'failure_category' => null,
                    'failure_message' => null,
                ]);
                $attempt->dispatchLoad()->update([
                    'notification_status' => 'sent',
                    'notified_at' => now(),
                ]);
            });
        } catch (DispatchNotificationException $exception) {
            $this->markFailed($exception->category, $exception->getMessage());

            if ($exception->retryable) {
                throw $exception;
            }
        } catch (Throwable $exception) {
            $this->markFailed(
                'unexpected',
                'An unexpected error prevented dispatch notification delivery.',
            );

            throw $exception;
        }
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function failed(?Throwable $exception): void
    {
        $this->markFailed(
            'retry-exhausted',
            'Dispatch notification delivery failed after the configured retry attempts.',
        );
    }

    private function markFailed(string $category, string $safeMessage): void
    {
        $attempt = DispatchNotificationAttempt::query()->find($this->attemptId);
        if ($attempt === null || $attempt->status === 'sent') {
            return;
        }

        $attempt->update([
            'status' => 'failed',
            'failure_category' => mb_substr($category, 0, 80),
            'failure_message' => mb_substr($safeMessage, 0, 500),
        ]);
        $attempt->dispatchLoad()->update(['notification_status' => 'failed']);
    }
}
