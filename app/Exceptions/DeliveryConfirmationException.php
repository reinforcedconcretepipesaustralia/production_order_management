<?php

namespace App\Exceptions;

use RuntimeException;

class DeliveryConfirmationException extends RuntimeException
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
        return new self(
            'configuration',
            'Delivery confirmation email provider configuration is incomplete.',
        );
    }

    public static function authentication(): self
    {
        return new self(
            'authentication',
            'The delivery confirmation email provider rejected its credentials.',
        );
    }

    public static function unavailable(): self
    {
        return new self(
            'unavailable',
            'The delivery confirmation email provider could not be reached.',
            retryable: true,
        );
    }

    public static function transport(): self
    {
        return new self(
            'transport',
            'The delivery confirmation email provider rejected the send request.',
            retryable: true,
        );
    }

    public static function writeConfiguration(): self
    {
        return new self(
            'write-configuration',
            'The RCPA SQL write connection is not configured.',
        );
    }

    public static function writeFailed(): self
    {
        return new self(
            'write-failed',
            'The delivery date could not be written to Tencia.',
            retryable: true,
        );
    }
}
