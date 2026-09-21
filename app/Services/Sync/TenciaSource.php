<?php

namespace App\Services\Sync;

use App\Models\Depot;
use App\Models\Rcpa\VwSALESORDERSView;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Where each question about Tencia is actually answered.
 *
 * The consolidated TenciaRCP_View is preferred. vwSTOCK_ON_HAND carries only rings and
 * seconds — no pipes — so stock falls back to the per-state databases, which the same login can
 * read. The future order book is dbo.vwSALES_ORDERS, with the same fallback until the read-only
 * login is granted SELECT on the state tables that view reads. Invoiced history is dbo.vwSALES.
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

    private ?bool $consolidatedOrderBookReadable = null;

    /**
     * A consolidated view in TenciaRCP_View.
     */
    public function view(string $name): Builder
    {
        return $this->connection()->table(DB::raw($this->viewName($name)));
    }

    /**
     * Open sales-order lines for one entity: the future order book.
     *
     * Invoiced pipes are dbo.vwSALES and are not read here. Headers with STATUS_FLAG in C, S,
     * or X, cancelled lines, and lines with nothing left to make are excluded either way.
     *
     * dbo.vwSALES_ORDERS is the source whenever it can be read. It reads TRANS_LINES in each
     * state database, and cross-database ownership chaining does not apply, so the read-only
     * login is denied until a DBA grants SELECT on those tables. Until then the same order book
     * is assembled from SOR_LINES and SOR_MASTER, which the login can read.
     */
    public function openSalesOrderLines(string $entityCode): Builder
    {
        return $this->consolidatedOrderBookIsReadable()
            ? $this->orderBookFromConsolidatedView($entityCode)
            : $this->orderBookFromStateTables($entityCode);
    }

    /**
     * Whether dbo.vwSALES_ORDERS answers a read, checked once per instance because the answer
     * only changes when a DBA grants the missing permission.
     */
    public function consolidatedOrderBookIsReadable(): bool
    {
        return $this->consolidatedOrderBookReadable ??= $this->probeConsolidatedOrderBook();
    }

    private function probeConsolidatedOrderBook(): bool
    {
        try {
            $this->view('vwSALES_ORDERS')->selectRaw('1')->limit(1)->get();
        } catch (Throwable) {
            return false;
        }

        return true;
    }

    private function orderBookFromConsolidatedView(string $entityCode): Builder
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
     * The same order book from one state's own database, shaped like the consolidated view so
     * callers cannot tell which source answered.
     */
    private function orderBookFromStateTables(string $entityCode): Builder
    {
        return $this->stateTable($entityCode, 'SOR_LINES', 'l')
            ->join(
                DB::raw($this->stateTableName($entityCode, 'SOR_MASTER').' AS m'),
                function ($join) {
                    $join->on('m.REFERENCE_NBR', '=', 'l.REFERENCE_NBR')
                        ->on('m.REFERENCE_DUPLICATE', '=', 'l.REFERENCE_DUPLICATE')
                        ->on('m.TRANS_TYPE', '=', 'l.TRANS_TYPE');
                },
            )
            ->where('m.TRANS_TYPE', 'DRSOR')
            ->whereNotIn('m.STATUS_FLAG', VwSALESORDERSView::EXCLUDED_HEADER_STATUS_FLAGS)
            ->where('l.LINE_STATUS', '<>', 'C')
            ->where('l.BACKORDER_QTY', '>', 0)
            ->orderBy('l.DELIVERY_DATE')
            ->select([
                'l.UNIQUE_ID', 'l.REFERENCE_NBR', 'l.LINE_NBR', 'l.STOCK_CODE', 'l.STOCK_LOCATION',
                'l.DESCRIPTION', 'l.BACKORDER_QTY', 'l.ALLOCATED_QTY', 'l.CUSTOMER_CODE',
                'm.ORDER_DATE', 'm.DELIVERY_NAME', 'm.DELIVERY_NOTE_1',
                DB::raw('l.DELIVERY_DATE AS LINE_DELIVERY_DATE'),
            ]);
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
