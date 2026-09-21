<?php

namespace App\Models\Rcpa;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;

/**
 * Future order book: open sales-order lines still to make or ship.
 *
 * Invoiced pipe history lives on VwSALESView (DRINV / DRCDT), not here.
 */
class VwSALESORDERSView extends ReadOnlyView
{
    protected $table = 'dbo.vwSALES_ORDERS';

    protected $primaryKey = 'ENTITY';

    public $incrementing = false;

    /**
     * Header STATUS_FLAG values that are not an open order: cancelled, suspended, and closed.
     *
     * @var list<string>
     */
    public const EXCLUDED_HEADER_STATUS_FLAGS = ['C', 'S', 'X'];

    protected static function booted(): void
    {
        static::addGlobalScope('openHeader', function (Builder $query): void {
            $query->whereNotIn('STATUS_FLAG', self::EXCLUDED_HEADER_STATUS_FLAGS);
        });
    }

    /**
     * Lines that still belong on the future order book.
     */
    #[Scope]
    protected function outstanding(Builder $query): Builder
    {
        return $query
            ->where('TRANS_TYPE', 'DRSOR')
            ->where('LINE_STATUS', '<>', 'C')
            ->where('BACKORDER_QTY', '>', 0);
    }

    protected function casts(): array
    {
        return [
            'CREATED_DATE' => 'datetime',
            'LAST_CHANGE_DATE' => 'datetime',
            'ORDER_DATE' => 'datetime',
            'HEADER_DELIVERY_DATE' => 'datetime',
            'HEADER_ORIGINAL_DELIVERY_DATE' => 'datetime',
            'LINE_DELIVERY_DATE' => 'datetime',
            'LINE_LAST_DELIVERY_DATE' => 'datetime',
            'SALES_CONVERSION' => 'decimal:2',
            'UNIT_WEIGHT' => 'decimal:2',
            'SELL_PRICE' => 'decimal:2',
            'ORDER_QTY' => 'decimal:2',
            'ENTERED_ORDER_QTY' => 'decimal:2',
            'ORDER_WEIGHT' => 'decimal:2',
            'ORDER_PRICE_EX_GST' => 'decimal:2',
            'ORDER_TAX' => 'decimal:2',
            'DISC_PCT' => 'decimal:2',
            'DISC_VALUE' => 'decimal:2',
            'ORDER_PRICE_INC_GST' => 'decimal:2',
            'ALLOCATED_QTY' => 'decimal:2',
            'ENTERED_ALLOCATED_QTY' => 'decimal:2',
            'ALLOCATED_WEIGHT' => 'decimal:2',
            'ALLOCATED_PRICE_EX_GST' => 'decimal:2',
            'ALLOCATED_TAX' => 'decimal:2',
            'ALLOCATED_PRICE_INC_GST' => 'decimal:2',
            'BACKORDER_QTY' => 'decimal:2',
            'ENTERED_BACKORDER_QTY' => 'decimal:2',
            'BACKORDER_WEIGHT' => 'decimal:2',
            'BACKORDER_PRICE_EX_GST' => 'decimal:2',
            'BACKORDER_TAX' => 'decimal:2',
            'BACKORDER_PRICE_INC_GST' => 'decimal:2',
            'SHIPPED_QTY' => 'decimal:2',
            'ENTERED_SHIPPED_QTY' => 'decimal:2',
            'SHIPPED_WEIGHT' => 'decimal:2',
            'SHIPPED_PRICE_EX_GST' => 'decimal:2',
            'SHIPPED_TAX' => 'decimal:2',
            'SHIPPED_PRICE_INC_GST' => 'decimal:2',
            'INVOICED_QTY' => 'decimal:2',
            'ENTERED_INVOICED_QTY' => 'decimal:2',
            'INVOICED_WEIGHT' => 'decimal:2',
            'INVOICED_EX_TAX_VALUE' => 'decimal:2',
            'INVOICED_TAX_VALUE' => 'decimal:2',
            'INVOICED_NET_VALUE' => 'decimal:2',
        ];
    }
}
