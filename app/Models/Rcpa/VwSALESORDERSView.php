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
     * Cancelled header (STATUS_FLAG) or cancelled line (LINE_STATUS). Neither belongs on the order book.
     */
    public const CANCELLED_STATUS = 'C';

    protected static function booted(): void
    {
        static::addGlobalScope('openOrder', function (Builder $query): void {
            self::excludeCancelled($query);
        });
    }

    /**
     * Lines that still belong on the future order book: not cancelled, with quantity left to make.
     *
     * The view has no TRANS_TYPE column. Invoiced sales are VwSALESView.
     */
    #[Scope]
    protected function outstanding(Builder $query): Builder
    {
        return $query->where('BACKORDER_QTY', '>', 0);
    }

    public static function excludeCancelled(object $query): void
    {
        $query
            ->where('STATUS_FLAG', '<>', self::CANCELLED_STATUS)
            ->where('LINE_STATUS', '<>', self::CANCELLED_STATUS);
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
