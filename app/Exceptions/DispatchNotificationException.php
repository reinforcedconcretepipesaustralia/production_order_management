<?php

namespace App\Exceptions;

use RuntimeException;

class DispatchNotificationException extends RuntimeException
{
    public function __construct(
        public readonly string $category,
        string $safeMessage,
        public readonly bool $retryable = false,
    ) {
        parent::__construct($safeMessage);
    }

    public static function configuration(): self
    {
        return new self('configuration', 'Dispatch email provider configuration is incomplete.');
    }

    public static function authentication(): self
    {
        return new self('authentication', 'The dispatch email provider rejected its credentials.');
    }

    public static function rateLimited(): self
    {
        return new self('rate-limited', 'The dispatch email provider is temporarily rate limited.', true);
    }

    public static function unavailable(): self
    {
        return new self('provider-unavailable', 'The dispatch email provider is temporarily unavailable.', true);
    }

    public static function rejected(): self
    {
        return new self('provider-rejected', 'The dispatch email provider rejected the notification.');
    }

    public static function transportUncertain(): self
    {
        return new self(
            'delivery-uncertain',
            'The provider connection ended before delivery could be confirmed; review before retrying.',
        );
    }
}
