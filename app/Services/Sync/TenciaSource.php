<?php

namespace App\Services\Sync;

use App\Models\Depot;
use App\Models\Rcpa\VwSALESORDERSView;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Where each question about Tencia is actually answered.
 *
 * The consolidated TenciaRCP_View is preferred. vwSTOCK_ON_HAND carries only rings and
 * seconds — no pipes — so stock falls back to the per-state databases, which the same login can
 * read. The future order book is always dbo.vwSALES_ORDERS. Invoiced history is dbo.vwSALES.
 * docs/erp/contract.md records why for each import.
 *
 * Everything here is read-only. The rcpa_sql connection refuses writes at the driver level.
 */
class TenciaSource
{
    /** Tencia entity code to the per-state database that backs it. */
    private const STATE_DATABASES = [
        'RCPVIC' => 'TenciaRCP_VIC',
        'RCPNSW' => 'TenciaRCP_NSW',
        'RCPQLD' => 'TenciaRCP_QLD',
        'RCPSA' => 'TenciaRCP_SA',
    ];

    /** Western Australia is closing and is not planned. */
    public const EXCLUDED_ENTITIES = ['RCPWA'];

    /**
     * A consolidated view in TenciaRCP_View.
     */
    public function view(string $name): Builder
    {
        return $this->connection()->table(DB::raw($this->viewName($name)));
    }

    /**
     * Open sales-order lines for one entity from dbo.vwSALES_ORDERS.
     *
     * This is the future order book. Invoiced pipes are dbo.vwSALES and are not read here.
     * Headers with STATUS_FLAG in C, S, or X, cancelled lines, and lines with nothing left
     * to make are excluded.
     */
    public function openSalesOrderLines(string $entityCode): Builder
    {
        return $this->view('vwSALES_ORDERS')
            ->where('ENTITY', $entityCode)
            ->where('TRANS_TYPE', 'DRSOR')
            ->whereNotIn('STATUS_FLAG', VwSALESORDERSView::EXCLUDED_HEADER_STATUS_FLAGS)
            ->where('LINE_STATUS', '<>', 'C')
            ->where('BACKORDER_QTY', '>', 0)
            ->orderBy('LINE_DELIVERY_DATE');
    }

    /**
     * A table in one state's own database, for the gaps the consolidated views cannot fill.
     */
    public function stateTable(string $entityCode, string $table, ?string $alias = null): Builder
    {
        $qualified = $this->stateTableName($entityCode, $table);

        return $this->connection()->table(
            DB::raw($alias === null ? $qualified : $qualified.' AS '.$alias),
        );
    }

    /**
     * Overridden in tests so the syncs can run against a local fixture instead of the corporate
     * network. Production always reads the read-only Tencia connection.
     */
    protected function connection(): ConnectionInterface
    {
        return DB::connection('rcpa_sql');
    }

    protected function viewName(string $name): string
    {
        return "dbo.[{$name}]";
    }

    /**
     * Year and month of a column, for grouping history into months on the server rather than pulling
     * a hundred thousand rows into PHP to bucket them. Dialect-specific, so it is a seam.
     *
     * @return array{string, string}
     */
    public function monthParts(string $column): array
    {
        return ["YEAR([{$column}])", "MONTH([{$column}])"];
    }

    public function stateTableName(string $entityCode, string $table): string
    {
        return '['.$this->databaseFor($entityCode)."].dbo.[{$table}]";
    }

    public function databaseFor(string $entityCode): string
    {
        return self::STATE_DATABASES[$entityCode]
            ?? throw new UnknownTenciaEntityException($entityCode);
    }

    public function hasStateDatabase(string $entityCode): bool
    {
        return array_key_exists($entityCode, self::STATE_DATABASES);
    }

    /**
     * Entity codes that are worth syncing: those a planning-enabled plant is mapped to, plus the
     * holding yards whose demand is routed to a plant.
     *
     * @return list<string>
     */
    public function plannedEntities(): array
    {
        $mapped = Depot::query()
            ->whereNotNull('erp_entity_code')
            ->pluck('erp_entity_code')
            ->merge(array_keys(HoldingYardRouter::RULES))
            ->unique()
            ->reject(fn (string $entity): bool => in_array($entity, self::EXCLUDED_ENTITIES, true))
            ->filter(fn (string $entity): bool => $this->hasStateDatabase($entity))
            ->values();

        return $mapped->all();
    }

    /**
     * Plants keyed by the stock locations they own, so a Tencia stock location resolves to the
     * plant that holds it without guessing from the product.
     *
     * @return Collection<string, Depot> Keys are "ENTITY|LOCATION".
     */
    public function depotsByStockLocation(): Collection
    {
        $byLocation = collect();

        foreach (Depot::query()->whereNotNull('erp_entity_code')->get() as $depot) {
            $locations = array_merge(
                $depot->prime_stock_locations ?? [],
                $depot->rework_stock_locations ?? [],
                $depot->quarantine_stock_locations ?? [],
            );

            foreach ($locations as $location) {
                $byLocation->put($depot->erp_entity_code.'|'.$location, $depot);
            }
        }

        return $byLocation;
    }
}
