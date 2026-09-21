<?php

namespace App\Console\Commands;

use App\Services\Rcpa\RcpaViewReadService;
use App\Support\Rcpa\RcpaSqlFailureClassifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Throwable;

class RcpaSqlStatusCommand extends Command
{
    protected $signature = 'rcpa:sql-status
        {--views : Verify generated models can read each mapped SQL view}
        {--columns : With --views, list column names for readable views}';

    protected $description = 'Check the RCPA SQL Server connection without printing credentials';

    public function handle(RcpaViewReadService $reader): int
    {
        if (! extension_loaded('pdo_sqlsrv')) {
            $this->error('pdo_sqlsrv PHP extension is not loaded.');

            return self::FAILURE;
        }

        $database = config('database.connections.rcpa_sql.database');
        $username = config('database.connections.rcpa_sql.username');

        $this->line(sprintf(
            'Target: %s:%s (read-only). Credentials are not printed.',
            (string) config('database.connections.rcpa_sql.host'),
            (string) config('database.connections.rcpa_sql.port'),
        ));

        if (blank($database) || blank($username)) {
            $this->error('RCPA_SQL_DATABASE or RCPA_SQL_USERNAME is empty. Fill the RCPA_SQL_* keys in .env.');

            return self::FAILURE;
        }

        $reader->forgetConnectionFailure();

        $unresolvedHost = RcpaSqlFailureClassifier::unresolvedHostReason(
            (string) config('database.connections.rcpa_sql.host'),
        );

        if ($unresolvedHost !== null) {
            $this->error('RCPA SQL connection: failed');
            $this->line('SQLSTATE: unknown');
            $this->line('Reason: '.$unresolvedHost);
            $this->line('Connect to the corporate network or VPN, then retry.');

            return self::FAILURE;
        }

        try {
            Schema::connection('rcpa_sql')->getConnection()->getPdo();
        } catch (Throwable $exception) {
            $this->error('RCPA SQL connection: failed');
            $this->line('SQLSTATE: '.RcpaSqlFailureClassifier::sqlState($exception));
            $this->line('Reason: '.RcpaSqlFailureClassifier::reason($exception));
            $this->line('Connect to the corporate network or VPN, then retry.');

            return self::FAILURE;
        }

        $this->info('RCPA SQL connection: ok');

        if (! $this->option('views')) {
            return self::SUCCESS;
        }

        $hasUnreadableView = false;

        foreach ($reader->probeMappedViews() as $view) {
            if ($view['status'] === 'readable') {
                $this->line("[readable] {$view['table']}");

                if ($this->option('columns')) {
                    try {
                        $columns = collect(Schema::connection('rcpa_sql')->getColumns($view['table']))
                            ->pluck('name')
                            ->implode(', ');

                        $this->line("  Columns: {$columns}");
                    } catch (Throwable) {
                        $this->line('  Columns: (unavailable for this view)');
                    }
                }

                continue;
            }

            $hasUnreadableView = true;
            $this->line("[unreadable] {$view['table']}");

            if ($view['reason'] !== null) {
                $this->line("  Reason: {$view['reason']}");
            }
        }

        return $hasUnreadableView ? self::FAILURE : self::SUCCESS;
    }
}
