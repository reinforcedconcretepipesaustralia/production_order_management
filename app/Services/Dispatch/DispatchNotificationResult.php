<?php

namespace App\Services\Dispatch;

final readonly class DispatchNotificationResult
{
    public function __construct(
        public ?string $providerRequestId,
    ) {}
}
