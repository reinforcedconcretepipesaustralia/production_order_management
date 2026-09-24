<?php

namespace App\Services\Sync;

use App\Models\Depot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Monthly invoiced sales history, from dbo.vwSALES — pipes that have been invoiced (DRINV) or
 * credited (DRCDT). The future order book is dbo.vwSALES_ORDERS, not this view.
 *
 * Credits (DRCDT) are netted off invoices (DRINV) rather than counted as sales; a returned pipe did
 * not sell. The view holds three years, so a twelve-month average is always available. Nothing is
 * written to the local database.
 */
final class SalesHistorySync
{
    /** Invoices add to sales; credit notes subtract. */
    private const INVOICE = 'DRINV';

    private const CREDIT = 'DRCDT';

    private ?SyncResult $lastResult = null;

    /** @var Collection<int, array<string, mixed>>|null */
    private ?Collection $months = null;

    public function __construct(
        private TenciaSource $source,
        private ProductSync $products,
    ) {}

    /**
     * Monthly invoiced totals, read from Tencia and not stored.
     *
     * @return Collection<int, array{erp_entity_code: string, sku_master_id: int, month: string, quantity_ea: int, tonnes: float, net_value_ex_tax: float}>
     */
    public function months(int $window = 24): Collection
    {
        if ($this->months !== null) {
            return $this->months;
        }

        $result = new SyncResult;
        $from = CarbonImmutable::now()->startOfMonth()->subMonths(max(1, $window));
        $rows = [];

        foreach ($this->source->plannedEntities() as $entity) {
            foreach ($this->rowsFor($entity, $from) as $row) {
                $sku = $this->products->find($entity, trim((string) $row->STOCK_CODE));

                if ($sku === null) {
                    $result->count('skipped (not a planned product)');

                    continue;
                }

                $month = CarbonImmutable::create((int) $row->sales_year, (int) $row->sales_month, 1);
                $rows[] = [
                    'erp_entity_code' => $entity,
                    'sku_master_id' => $sku->id,
                    'month' => $month->toDateString(),
                    'quantity_ea' => (int) round((float) $row->quantity_ea),
                    'tonnes' => round((float) $row->tonnes, 4),
                    'net_value_ex_tax' => round((float) $row->net_value_ex, 2),
                ];
                $result->count('months of history');
            }
        }

        $this->lastResult = $result;

        return $this->months = collect($rows);
    }

    /**
     * @return Collection<int, array{r3m: float, r6m: float, r12m: float}>
     */
    public function velocityForDepot(Depot $depot, CarbonImmutable $asOf): Collection
    {
        $firstOfCurrentMonth = $asOf->startOfMonth();

        return $this->months()
            ->filter(fn (array $row): bool => $row['erp_entity_code'] === $depot->erp_entity_code
                && $row['month'] >= $firstOfCurrentMonth->subMonths(12)->toDateString()
                && $row['month'] < $firstOfCurrentMonth->toDateString())
            ->groupBy('sku_master_id')
            ->map(function (Collection $skuRows) use ($firstOfCurrentMonth): array {
                $sum = function (int $months) use ($skuRows, $firstOfCurrentMonth): float {
                    $from = $firstOfCurrentMonth->subMonths($months)->toDateString();

                    return (float) $skuRows
                        ->filter(fn (array $row): bool => $row['month'] >= $from)
                        ->sum('quantity_ea');
                };

                return [
                    'r3m' => $sum(3) / 3,
                    'r6m' => $sum(6) / 6,
                    'r12m' => $sum(12) / 12,
                ];
            });
    }

    public function result(): SyncResult
    {
        $this->months();

        return $this->lastResult ?? new SyncResult;
    }

    /**
     * @return Collection<int, object>
     */
    private function rowsFor(string $entity, CarbonImmutable $from): Collection
    {
        [$year, $month] = $this->source->monthParts('DATE');

        return $this->source->view('vwSALES')
            ->where('ENTITY', $entity)
            ->whereIn('TRANS_TYPE', [self::INVOICE, self::CREDIT])
            ->where('DATE', '>=', $from->toDateTimeString())
            ->groupBy('STOCK_CODE', DB::raw($year), DB::raw($month))
            ->orderBy('STOCK_CODE')
            ->get([
                'STOCK_CODE',
                DB::raw($year.' AS sales_year'),
                DB::raw($month.' AS sales_month'),
                DB::raw('SUM(CASE WHEN TRANS_TYPE = \''.self::CREDIT.'\' THEN -QUANTITY ELSE QUANTITY END) AS quantity_ea'),
                DB::raw('SUM(CASE WHEN TRANS_TYPE = \''.self::CREDIT.'\' THEN -Line_Weight ELSE Line_Weight END) AS tonnes'),
                DB::raw('SUM(CASE WHEN TRANS_TYPE = \''.self::CREDIT.'\' THEN -Net_Value_Ex ELSE Net_Value_Ex END) AS net_value_ex'),
            ]);
    }
}
