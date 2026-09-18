<?php

namespace App\Exceptions;

use RuntimeException;

class ReadOnlyConnectionException extends RuntimeException
{
    public static function rcpaSql(): self
    {
        return new self('The RCPA SQL connection is read-only.');
    }

    public static function forConnection(?string $connection): self
    {
        return new self(sprintf(
            'The [%s] database connection is read-only.',
            $connection ?? 'unknown'
        ));
    }
}
