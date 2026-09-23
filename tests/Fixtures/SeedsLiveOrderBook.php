<?php

namespace Tests\Fixtures;

use App\Models\Depot;
use App\Models\SkuMaster;

/**
 * Puts outstanding order lines on the fake Tencia view so planning tests read them the way
 * production does: from the view, not from a local sales_orders row.
 */
trait SeedsLiveOrderBook
{
    protected FakeTenciaSource $orderBookSource;

    protected function installLiveOrderBook(): void
    {
        $this->orderBookSource = FakeTenciaSource::install()->createTables();
    }

    protected function liveOrder(Depot $depot, SkuMaster $sku, int $quantity, string $due, int $allocated = 0, string $location = '31'): void
    {
        $entity = (string) $depot->erp_entity_code;

        if ($sku->erp_entity_code !== $entity) {
            $sku->forceFill(['erp_entity_code' => $entity])->save();
        }

        $remainder = max(0, $quantity - $allocated);

        $this->orderBookSource->addOrderLine($entity, [
            'STOCK_CODE' => $sku->sku_code,
            'STOCK_LOCATION' => $location,
            'BACKORDER_QTY' => $remainder,
            'LINE_DELIVERY_DATE' => $due.' 00:00:00',
            'REFERENCE_NBR' => $sku->sku_code.'-'.$due.'-'.$remainder,
        ]);
    }
}
