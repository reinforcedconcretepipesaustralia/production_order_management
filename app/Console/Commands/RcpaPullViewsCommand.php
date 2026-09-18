<?php

namespace App\Console\Commands;

use App\Services\Rcpa\RcpaViewReadService;
use Illuminate\Console\Command;

class RcpaPullViewsCommand extends Command
{
    protected $signature = 'rcpa:pull-views';

    protected $description = 'Pull mapped RCPA SQL views through Eloquent without printing credentials or row data';

    public function handle(RcpaViewReadService $reader): int
    {
        if (! extension_loaded('pdo_sqlsrv')) {
            $this->error('pdo_sqlsrv PHP extension is not loaded.');

            return self::FAILURE;
        }

        $this->line(sprintf(
            'Target: %s:%s (read-only). Credentials and row contents are not printed.',
            (string) config('database.connections.rcpa_sql.host'),
            (string) config('database.connections.rcpa_sql.port'),
        ));

        $counts = $reader->countMappedViews();
        $unreadable = false;

        foreach ($counts as $view) {
            if ($view['status'] === 'readable') {
                $this->line("[pulled] {$view['table']} rows={$view['row_count']}");

                continue;
            }

            $unreadable = true;
            $this->line("[unreadable] {$view['table']}");
        }

        if ($unreadable) {
            $this->error('One or more mapped views could not be pulled. Fill RCPA_SQL_* in .env and connect to the corporate network or VPN.');

            return self::FAILURE;
        }

        $this->info('Pulled all mapped RCPA views through Eloquent.');

        return self::SUCCESS;
    }
}
