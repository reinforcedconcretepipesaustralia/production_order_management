<?php

namespace App\Support\Rcpa;

use Throwable;

final class RcpaSqlFailureClassifier
{
    public static function sqlState(Throwable $exception): string
    {
        $code = (string) $exception->getCode();

        return $code !== '' ? $code : 'unknown';
    }

    public static function reason(Throwable $exception): string
    {
        $message = strtolower($exception->getMessage());

        return match (true) {
            str_contains($message, 'certificate') ||
                str_contains($message, 'ssl provider') => 'TLS certificate validation failed.',
            str_contains($message, 'login failed') => 'SQL Server rejected the login.',
            str_contains($message, 'timeout') => 'SQL Server connection timed out.',
            str_contains($message, 'tcp provider') ||
                str_contains($message, 'named pipes') ||
                str_contains($message, 'network-related') ||
                str_contains($message, 'server was not found') ||
                str_contains($message, 'not accessible') => 'SQL Server instance is unavailable.',
            default => 'See the application log or contact the database administrator.',
        };
    }
}
