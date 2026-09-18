<?php

namespace App\Console\Commands;

use App\Support\Rcpa\RcpaViewModelWriter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Throwable;

class RcpaMakeViewModelsCommand extends Command
{
    protected $signature = 'rcpa:make-view-models
        {views* : Schema-qualified or unqualified SQL view names}
        {--key=* : Required View=PrimaryKey mapping; repeat for each view}
        {--force : Overwrite existing model files}';

    protected $description = 'Generate read-only Eloquent models for RCPA SQL views without printing credentials';

    public function handle(RcpaViewModelWriter $writer): int
    {
        if (! extension_loaded('pdo_sqlsrv')) {
            $this->error('pdo_sqlsrv PHP extension is not loaded.');

            return self::FAILURE;
        }

        if (blank(config('database.connections.rcpa_sql.database')) || blank(config('database.connections.rcpa_sql.username'))) {
            $this->error('RCPA_SQL_DATABASE or RCPA_SQL_USERNAME is empty. Fill the RCPA_SQL_* keys in .env.');

            return self::FAILURE;
        }

        $views = $this->argument('views');
        $primaryKeys = $this->primaryKeys();

        foreach ($views as $view) {
            if (! isset($primaryKeys[$view])) {
                $this->error("No primary key supplied for [{$view}]. Use --key=\"{$view}=ColumnName\".");

                return self::FAILURE;
            }

            try {
                $columns = Schema::connection('rcpa_sql')->getColumns($view);
            } catch (Throwable $exception) {
                $this->error("Could not inspect view [{$view}]. SQLSTATE: ".$this->sqlState($exception));

                return self::FAILURE;
            }

            if ($columns === []) {
                $this->error("View [{$view}] has no columns visible to this login.");

                return self::FAILURE;
            }

            $path = $writer->path($view);

            if (file_exists($path) && ! $this->option('force')) {
                $this->warn("Skipped {$writer->className($view)} (already exists). Use --force to overwrite.");

                continue;
            }

            try {
                $contents = $writer->contents($view, $columns, $primaryKeys[$view]);
            } catch (\InvalidArgumentException $exception) {
                $this->error($exception->getMessage());

                return self::FAILURE;
            }

            file_put_contents($path, $contents);
            $this->info("Wrote {$writer->className($view)}");
        }

        return self::SUCCESS;
    }

    private function sqlState(Throwable $exception): string
    {
        $code = (string) $exception->getCode();

        return $code !== '' ? $code : 'unknown';
    }

    /**
     * @return array<string, string>
     */
    private function primaryKeys(): array
    {
        $primaryKeys = [];

        foreach ($this->option('key') as $mapping) {
            [$view, $column] = array_pad(explode('=', $mapping, 2), 2, null);

            if (blank($view) || blank($column)) {
                continue;
            }

            $primaryKeys[$view] = $column;
        }

        return $primaryKeys;
    }
}
