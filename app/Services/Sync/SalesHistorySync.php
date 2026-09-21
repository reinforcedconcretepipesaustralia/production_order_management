<?php

namespace App\Services\Sync;

use App\Models\Depot;
use App\Models\SalesHistoryMonthly;
use App\Models\SkuMaster;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Monthly invoiced sales history, from dbo.vwSALES — pipes that have been invoiced (DRINV) or
 * credited (DRCDT). The future order book is dbo.vwSALES_ORDERS, not this view.
 *
 * Credits (DRCDT) are netted off invoices (DRINV) rather than counted as sales; a returned pipe did
 * not sell. The view holds three years, so a twelve-month average is always available.
 */
final class SalesHistorySync
{
    /** Invoices add to sales; credit notes subtract. */
    private const INVOICE = 'DRINV';

    private const CREDIT = 'DRCDT';

    public function __construct(private TenciaSource $source) {}

    public function sync(int $months = 24): SyncResult
    {
        $result = new SyncResult;
        $skus = $this->skusByEntityAndCode();
        $from = CarbonImmutable::now()->startOfMonth()->subMonths(max(1, $months));

        foreach ($this->source->plannedEntities() as $entity) {
            $depot = Depot::query()->where('erp_entity_code', $entity)->first();

            if ($depot === null) {
                // A holding yard's sales are not a plant's production history.
                continue;
            }

            $this->syncEntity($entity, $depot, $skus, $from, $result);
        }

        return $result;
    }

    /**
     * @param  Collection<string, SkuMaster>  $skus
     */
    private function syncEntity(
        string $entity,
        Depot $depot,
        Collection $skus,
        CarbonImmutable $from,
        SyncResult $result,
    ): void {
        [$year, $month] = $this->source->monthParts('DATE');

        $rows = $this->source->view('vwSALES')
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

        foreach ($rows as $row) {
            $sku = $skus->get($entity.'|'.trim((string) $row->STOCK_CODE));

            if ($sku === null) {
                $result->count('skipped (not a planned product)');

                continue;
            }

            $month = CarbonImmutable::create((int) $row->sales_year, (int) $row->sales_month, 1);

            SalesHistoryMonthly::query()->updateOrCreate(
                [
                    'depot_id' => $depot->id,
                    'sku_master_id' => $sku->id,
                    // The Carbon instance, not a string: the date cast persists a midnight time, so
                    // a 'Y-m-d' string never matches an existing row and duplicates it instead.
                    'month' => $month,
                ],
                [
                    'quantity_ea' => (int) round((float) $row->quantity_ea),
                    'tonnes' => round((float) $row->tonnes, 4),
                    'net_value_ex_tax' => round((float) $row->net_value_ex, 2),
                ],
            );

            $result->count('months of history');
        }
    }

    /**
     * @return Collection<string, SkuMaster> Keys are "ENTITY|STOCK_CODE".
     */
    private function skusByEntityAndCode(): Collection
    {
        return SkuMaster::query()
            ->whereNotNull('erp_entity_code')
            ->get(['id', 'sku_code', 'erp_entity_code'])
            ->keyBy(fn (SkuMaster $sku): string => $sku->erp_entity_code.'|'.$sku->sku_code);
    }
}
