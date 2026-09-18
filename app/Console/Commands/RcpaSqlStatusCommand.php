<?php

namespace App\Console\Commands;

use App\Support\Rcpa\RcpaViewModelWriter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Throwable;

class RcpaSqlStatusCommand extends Command
{
    protected $signature = 'rcpa:sql-status
        {--views : Verify generated models can read each SQL view}
        {--columns : With --views, list column names for readable views}';

    protected $description = 'Check the RCPA SQL Server connection without printing credentials';

    public function handle(RcpaViewModelWriter $writer): int
    {
        if (! extension_loaded('pdo_sqlsrv')) {
            $this->error('pdo_sqlsrv PHP extension is not loaded.');

            return self::FAILURE;
        }

        $database = config('database.connections.rcpa_sql.database');
        $username = config('database.connections.rcpa_sql.username');

        if (blank($database) || blank($username)) {
            $this->error('RCPA_SQL_DATABASE or RCPA_SQL_USERNAME is empty. Fill the RCPA_SQL_* keys in .env.');

            return self::FAILURE;
        }

        try {
            Schema::connection('rcpa_sql')->getConnection()->getPdo();
        } catch (Throwable $exception) {
            $this->error('RCPA SQL connection: failed');
            $this->line('SQLSTATE: '.$this->sqlState($exception));
            $this->line('Reason: '.$this->safeFailureReason($exception));

            return self::FAILURE;
        }

        $this->info('RCPA SQL connection: ok');

        if ($this->option('views')) {
            $views = collect(Schema::connection('rcpa_sql')->getViews())
                ->map(fn (array $view): string => (string) ($view['schema_qualified_name'] ?? $view['name']))
                ->filter()
                ->sort()
                ->values();

            $this->info('Views: '.$views->count());

            $hasUnreadableView = false;

            foreach ($views as $view) {
                $model = 'App\\Models\\Rcpa\\'.$writer->className($view);

                if (! class_exists($model)) {
                    $this->line("[unmapped] {$view}");

                    continue;
                }

                try {
                    $model::query()->limit(1)->exists();
                    $this->line("[readable] {$view}");

                    if ($this->option('columns')) {
                        $columns = collect(Schema::connection('rcpa_sql')->getColumns($view))
                            ->pluck('name')
                            ->implode(', ');

                        $this->line("  Columns: {$columns}");
                    }
                } catch (Throwable $exception) {
                    $hasUnreadableView = true;
                    $this->line("[unreadable] {$view} (SQLSTATE {$this->sqlState($exception)})");
                }
            }

            if ($hasUnreadableView) {
                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }

    private function sqlState(Throwable $exception): string
    {
        $code = (string) $exception->getCode();

        return $code !== '' ? $code : 'unknown';
    }

    private function safeFailureReason(Throwable $exception): string
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
