<?php

namespace App\Services\Dispatch;

final readonly class DispatchNotification
{
    /**
     * @param  list<string>  $recipients
     */
    public function __construct(
        public array $recipients,
        public string $subject,
        public string $htmlBody,
        public string $idempotencyKey,
    ) {}
}
