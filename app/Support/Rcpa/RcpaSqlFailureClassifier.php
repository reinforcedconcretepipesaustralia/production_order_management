<?php

namespace App\Support\Rcpa;

use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;
use Throwable;

final class RcpaSqlFailureClassifier
{
    public const UNRESOLVED_HOST = 'The SQL Server host could not be resolved. Join the corporate network or VPN.';

    public static function sqlState(Throwable $exception): string
    {
        $code = (string) $exception->getCode();

        return $code !== '' ? $code : 'unknown';
    }

    /**
     * Skip the SQL handshake when the hostname is not in DNS, which is the
     * usual off-VPN failure for RCP-AUS-SQL01.
     *
     * Lookup runs in a short-lived process because Windows can block
     * gethostbyname() for a long time against unreachable corporate DNS.
     *
     * Only a lookup that answers with the hostname itself is treated as off-network. A lookup that
     * cannot answer at all says nothing about the network, so it falls through to the handshake
     * rather than blaming the VPN — the login timeout already bounds how long that can take.
     *
     * @param  string|null  $resolved  Pass a resolved address to skip a live DNS lookup (tests).
     */
    public static function unresolvedHostReason(string $host, ?string $resolved = null): ?string
    {
        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return null;
        }

        $resolved ??= self::lookupHost($host);

        if ($resolved === $host) {
            return self::UNRESOLVED_HOST;
        }

        return null;
    }

    /**
     * Resolve a hostname with a hard timeout so stale corporate DNS cannot stall a page.
     *
     * Returns null when the lookup is inconclusive, which is not the same as an unresolved host.
     */
    private static function lookupHost(string $host): ?string
    {
        try {
            // Not quietly(): that discards the output this lookup exists to read.
            $result = Process::timeout(2)->run([
                PHP_BINARY,
                '-r',
                'echo gethostbyname('.var_export($host, true).');',
            ]);
        } catch (ProcessTimedOutException) {
            return null;
        }

        $resolved = trim($result->output());

        return $result->successful() && $resolved !== '' ? $resolved : null;
    }

    public static function reason(Throwable $exception): string
    {
        $message = strtolower($exception->getMessage());

        return match (true) {
            str_contains($message, 'permission was denied') ||
                str_contains($message, 'permission denied') => 'The read-only login lacks SELECT permission on this view or its underlying tables. Ask the DBA to grant it.',
            str_contains($message, 'invalid object name') => 'This view does not exist on the target database.',
            str_contains($message, 'certificate') ||
                str_contains($message, 'ssl provider') => 'TLS certificate validation failed.',
            str_contains($message, 'login failed') => 'SQL Server rejected the login.',
            str_contains($message, 'getaddrinfo') ||
                str_contains($message, 'no such host') ||
                str_contains($message, 'name or service not known') => self::UNRESOLVED_HOST,
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
