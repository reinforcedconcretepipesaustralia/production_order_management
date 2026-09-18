<?php

namespace App\Services\Rcpa;

use App\Models\Rcpa\ReadOnlyView;
use App\Models\Rcpa\VwBOMLINESView;
use App\Models\Rcpa\VwProductionView;
use App\Models\Rcpa\VwSALESORDERSView;
use App\Models\Rcpa\VwSTKMASTERView;
use App\Models\Rcpa\VwSTOCKONHANDView;
use App\Support\Rcpa\RcpaSqlFailureClassifier;
use App\Support\Rcpa\RcpaViewCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class RcpaViewReadService
{
    private const CONNECTION_FAILURE_CACHE_KEY = 'rcpa_sql.connection_failure_reason';

    private const CONNECTION_FAILURE_CACHE_SECONDS = 60;

    private bool $connectionChecked = false;

    private ?string $connectionFailureReason = null;

    public function __construct(private RcpaViewCatalog $catalog) {}

    /**
     * Discard the cached connection failure so the next read retries the server.
     */
    public function forgetConnectionFailure(): void
    {
        $this->connectionChecked = false;
        $this->connectionFailureReason = null;

        Cache::forget(self::CONNECTION_FAILURE_CACHE_KEY);
    }

    /**
     * @return list<array{table: string, model: string, status: string, reason: ?string, has_row: bool, identifier: ?string}>
     */
    public function probeMappedViews(): array
    {
        $connectionReason = $this->connectionFailureReason();

        if ($connectionReason !== null) {
            return array_map(
                fn (string $modelClass): array => $this->result($modelClass, 'unreadable', $connectionReason),
                $this->catalog->models(),
            );
        }

        $results = [];

        foreach ($this->catalog->models() as $modelClass) {
            try {
                $record = $modelClass::query()->limit(1)->first();
                $results[] = $this->result(
                    $modelClass,
                    'readable',
                    null,
                    $record !== null,
                    $record === null ? null : (string) $record->getAttribute((new $modelClass)->getKeyName()),
                );
            } catch (Throwable $exception) {
                $results[] = $this->result(
                    $modelClass,
                    'unreadable',
                    RcpaSqlFailureClassifier::reason($exception),
                );
            }
        }

        return $results;
    }

    /**
     * @return list<array{table: string, model: string, status: string, reason: ?string, row_count: int}>
     */
    public function countMappedViews(): array
    {
        $connectionReason = $this->connectionFailureReason();

        if ($connectionReason !== null) {
            return array_map(
                fn (string $modelClass): array => $this->countResult($modelClass, 'unreadable', $connectionReason, 0),
                $this->catalog->models(),
            );
        }

        $results = [];

        foreach ($this->catalog->models() as $modelClass) {
            try {
                $results[] = $this->countResult(
                    $modelClass,
                    'readable',
                    null,
                    $modelClass::query()->count(),
                );
            } catch (Throwable $exception) {
                $results[] = $this->countResult(
                    $modelClass,
                    'unreadable',
                    RcpaSqlFailureClassifier::reason($exception),
                    0,
                );
            }
        }

        return $results;
    }

    /**
     * @return array{status: string, reason: ?string, rows: list<array{entity: string, order_date: string, required_date: string, qty_ordered: string, qty_used: string}>}
     */
    public function productionDemand(string $fromDate, string $toDate, int $limit = 50): array
    {
        $connectionReason = $this->connectionFailureReason();

        if ($connectionReason !== null) {
            return [
                'status' => 'unreadable',
                'reason' => $connectionReason,
                'rows' => [],
            ];
        }

        try {
            $from = CarbonImmutable::parse($fromDate)->startOfDay();
            $to = CarbonImmutable::parse($toDate)->endOfDay();

            $records = VwProductionView::query()
                ->select(['ENTITY', 'ORDER_DATE', 'REQUIRED_DATE', 'QTY_ORDERED', 'QTY_USED'])
                ->whereBetween('REQUIRED_DATE', [$from->toDateTimeString(), $to->toDateTimeString()])
                ->orderBy('REQUIRED_DATE')
                ->limit(max(1, min($limit, 100)))
                ->get();
        } catch (Throwable $exception) {
            return [
                'status' => 'unreadable',
                'reason' => RcpaSqlFailureClassifier::reason($exception),
                'rows' => [],
            ];
        }

        return [
            'status' => 'readable',
            'reason' => null,
            'rows' => $records->map(function (VwProductionView $record): array {
                $orderDate = $record->getAttribute('ORDER_DATE');
                $requiredDate = $record->getAttribute('REQUIRED_DATE');

                return [
                    'entity' => (string) $record->getAttribute('ENTITY'),
                    'order_date' => $orderDate instanceof \DateTimeInterface ? $orderDate->format('Y-m-d') : (string) $orderDate,
                    'required_date' => $requiredDate instanceof \DateTimeInterface ? $requiredDate->format('Y-m-d') : (string) $requiredDate,
                    'qty_ordered' => (string) $record->getAttribute('QTY_ORDERED'),
                    'qty_used' => (string) $record->getAttribute('QTY_USED'),
                ];
            })->all(),
        ];
    }

    /**
     * @return array{status: string, reason: ?string, rows: list<array{entity: string, qty_on_hand: string, qty_allocated: string, qty_available: string}>}
     */
    public function stockOnHand(int $limit = 50): array
    {
        $connectionReason = $this->connectionFailureReason();

        if ($connectionReason !== null) {
            return [
                'status' => 'unreadable',
                'reason' => $connectionReason,
                'rows' => [],
            ];
        }

        try {
            $records = VwSTOCKONHANDView::query()
                ->select(['ENTITY', 'Qty On Hand', 'Qty Allocated', 'Qty Available'])
                ->orderBy('ENTITY')
                ->limit(max(1, min($limit, 100)))
                ->get();
        } catch (Throwable $exception) {
            return [
                'status' => 'unreadable',
                'reason' => RcpaSqlFailureClassifier::reason($exception),
                'rows' => [],
            ];
        }

        return [
            'status' => 'readable',
            'reason' => null,
            'rows' => $records->map(fn (VwSTOCKONHANDView $record): array => [
                'entity' => (string) $record->getAttribute('ENTITY'),
                'qty_on_hand' => (string) $record->getAttribute('Qty On Hand'),
                'qty_allocated' => (string) $record->getAttribute('Qty Allocated'),
                'qty_available' => (string) $record->getAttribute('Qty Available'),
            ])->all(),
        ];
    }

    /**
     * @return array{status: string, reason: ?string, rows: list<array{entity: string, order_date: string, delivery_date: string, order_qty: string, allocated_qty: string, backorder_qty: string}>}
     */
    public function openSalesOrders(string $fromDate, string $toDate, int $limit = 50): array
    {
        $connectionReason = $this->connectionFailureReason();

        if ($connectionReason !== null) {
            return [
                'status' => 'unreadable',
                'reason' => $connectionReason,
                'rows' => [],
            ];
        }

        try {
            $from = CarbonImmutable::parse($fromDate)->startOfDay();
            $to = CarbonImmutable::parse($toDate)->endOfDay();

            $records = VwSALESORDERSView::query()
                ->select(['ENTITY', 'ORDER_DATE', 'LINE_DELIVERY_DATE', 'ORDER_QTY', 'ALLOCATED_QTY', 'BACKORDER_QTY'])
                ->whereBetween('LINE_DELIVERY_DATE', [$from->toDateTimeString(), $to->toDateTimeString()])
                ->orderBy('LINE_DELIVERY_DATE')
                ->limit(max(1, min($limit, 100)))
                ->get();
        } catch (Throwable $exception) {
            return [
                'status' => 'unreadable',
                'reason' => RcpaSqlFailureClassifier::reason($exception),
                'rows' => [],
            ];
        }

        return [
            'status' => 'readable',
            'reason' => null,
            'rows' => $records->map(function (VwSALESORDERSView $record): array {
                $orderDate = $record->getAttribute('ORDER_DATE');
                $deliveryDate = $record->getAttribute('LINE_DELIVERY_DATE');

                return [
                    'entity' => (string) $record->getAttribute('ENTITY'),
                    'order_date' => $orderDate instanceof \DateTimeInterface ? $orderDate->format('Y-m-d') : (string) $orderDate,
                    'delivery_date' => $deliveryDate instanceof \DateTimeInterface ? $deliveryDate->format('Y-m-d') : (string) $deliveryDate,
                    'order_qty' => (string) $record->getAttribute('ORDER_QTY'),
                    'allocated_qty' => (string) $record->getAttribute('ALLOCATED_QTY'),
                    'backorder_qty' => (string) $record->getAttribute('BACKORDER_QTY'),
                ];
            })->all(),
        ];
    }

    /**
     * @return array{status: string, reason: ?string, rows: list<array{entity: string, unit_weight: string, sales_conversion: string, standard_cost: string}>}
     */
    public function skuMaster(int $limit = 50): array
    {
        $connectionReason = $this->connectionFailureReason();

        if ($connectionReason !== null) {
            return [
                'status' => 'unreadable',
                'reason' => $connectionReason,
                'rows' => [],
            ];
        }

        try {
            $records = VwSTKMASTERView::query()
                ->select(['ENTITY', 'UNIT_WEIGHT', 'SALES_CONVERSION', 'STANDARD_COST'])
                ->orderBy('ENTITY')
                ->limit(max(1, min($limit, 100)))
                ->get();
        } catch (Throwable $exception) {
            return [
                'status' => 'unreadable',
                'reason' => RcpaSqlFailureClassifier::reason($exception),
                'rows' => [],
            ];
        }

        return [
            'status' => 'readable',
            'reason' => null,
            'rows' => $records->map(fn (VwSTKMASTERView $record): array => [
                'entity' => (string) $record->getAttribute('ENTITY'),
                'unit_weight' => (string) $record->getAttribute('UNIT_WEIGHT'),
                'sales_conversion' => (string) $record->getAttribute('SALES_CONVERSION'),
                'standard_cost' => (string) $record->getAttribute('STANDARD_COST'),
            ])->all(),
        ];
    }

    /**
     * @return array{status: string, reason: ?string, rows: list<array{entity: string, bill_order_qty: string}>}
     */
    public function bomLines(int $limit = 50): array
    {
        $connectionReason = $this->connectionFailureReason();

        if ($connectionReason !== null) {
            return [
                'status' => 'unreadable',
                'reason' => $connectionReason,
                'rows' => [],
            ];
        }

        try {
            $records = VwBOMLINESView::query()
                ->select(['ENTITY', 'BILL_ORDER_QTY'])
                ->orderBy('ENTITY')
                ->limit(max(1, min($limit, 100)))
                ->get();
        } catch (Throwable $exception) {
            return [
                'status' => 'unreadable',
                'reason' => RcpaSqlFailureClassifier::reason($exception),
                'rows' => [],
            ];
        }

        return [
            'status' => 'readable',
            'reason' => null,
            'rows' => $records->map(fn (VwBOMLINESView $record): array => [
                'entity' => (string) $record->getAttribute('ENTITY'),
                'bill_order_qty' => (string) $record->getAttribute('BILL_ORDER_QTY'),
            ])->all(),
        ];
    }

    /**
     * @return array{columns: list<string>, rows: list<array<string, string>>}
     */
    public function previewMappedView(string $table, int $limit = 10): array
    {
        $empty = ['columns' => [], 'rows' => []];
        $modelClass = $this->modelClassForTable($table);

        if ($modelClass === null || $this->connectionFailureReason() !== null) {
            return $empty;
        }

        try {
            $records = $modelClass::query()->limit(max(1, min($limit, 25)))->get();
        } catch (Throwable) {
            return $empty;
        }

        if ($records->isEmpty()) {
            return $empty;
        }

        $columns = array_keys($records->first()->getAttributes());

        return [
            'columns' => $columns,
            'rows' => $records->map(
                function (ReadOnlyView $record) use ($columns): array {
                    $row = [];

                    foreach ($columns as $column) {
                        $value = $record->getAttribute($column);
                        $row[$column] = $value === null ? '' : (string) $value;
                    }

                    return $row;
                },
            )->all(),
        ];
    }

    /**
     * @return class-string<ReadOnlyView>|null
     */
    private function modelClassForTable(string $table): ?string
    {
        foreach ($this->catalog->models() as $modelClass) {
            if ((new $modelClass)->getTable() === $table) {
                return $modelClass;
            }
        }

        return null;
    }

    /**
     * @param  class-string<ReadOnlyView>  $modelClass
     * @return array{table: string, model: string, status: string, reason: ?string, row_count: int}
     */
    private function countResult(string $modelClass, string $status, ?string $reason, int $rowCount): array
    {
        $model = new $modelClass;

        return [
            'table' => $model->getTable(),
            'model' => class_basename($modelClass),
            'status' => $status,
            'reason' => $reason,
            'row_count' => $rowCount,
        ];
    }

    /**
     * @param  class-string<ReadOnlyView>  $modelClass
     * @return array{table: string, model: string, status: string, reason: ?string, has_row: bool, identifier: ?string}
     */
    private function result(
        string $modelClass,
        string $status,
        ?string $reason,
        bool $hasRow = false,
        ?string $identifier = null,
    ): array {
        $model = new $modelClass;

        return [
            'table' => $model->getTable(),
            'model' => class_basename($modelClass),
            'status' => $status,
            'reason' => $reason,
            'has_row' => $hasRow,
            'identifier' => $identifier,
        ];
    }

    private function connectionFailureReason(): ?string
    {
        if ($this->connectionChecked) {
            return $this->connectionFailureReason;
        }

        $this->connectionChecked = true;

        return $this->connectionFailureReason = $this->resolveConnectionFailureReason();
    }

    /**
     * A failed handshake is remembered briefly so an unreachable server cannot
     * stall a page with one login timeout per view that it renders.
     */
    private function resolveConnectionFailureReason(): ?string
    {
        if (blank(config('database.connections.rcpa_sql.database')) || blank(config('database.connections.rcpa_sql.username'))) {
            return 'RCPA_SQL_DATABASE or RCPA_SQL_USERNAME is empty. Fill the RCPA_SQL_* keys in .env.';
        }

        $cachedReason = Cache::get(self::CONNECTION_FAILURE_CACHE_KEY);

        if (is_string($cachedReason)) {
            return $cachedReason;
        }

        try {
            Schema::connection('rcpa_sql')->getConnection()->getPdo();
        } catch (Throwable $exception) {
            $reason = RcpaSqlFailureClassifier::reason($exception);

            Cache::put(self::CONNECTION_FAILURE_CACHE_KEY, $reason, self::CONNECTION_FAILURE_CACHE_SECONDS);

            return $reason;
        }

        return null;
    }
}
