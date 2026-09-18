<?php

namespace App\Services\Rcpa;

use App\Models\Rcpa\ReadOnlyView;
use App\Models\Rcpa\VwProductionView;
use App\Support\Rcpa\RcpaSqlFailureClassifier;
use App\Support\Rcpa\RcpaViewCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class RcpaViewReadService
{
    public function __construct(private RcpaViewCatalog $catalog) {}

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
        if (blank(config('database.connections.rcpa_sql.database')) || blank(config('database.connections.rcpa_sql.username'))) {
            return 'RCPA_SQL_DATABASE or RCPA_SQL_USERNAME is empty. Fill the RCPA_SQL_* keys in .env.';
        }

        try {
            Schema::connection('rcpa_sql')->getConnection()->getPdo();
        } catch (Throwable $exception) {
            return RcpaSqlFailureClassifier::reason($exception);
        }

        return null;
    }
}
