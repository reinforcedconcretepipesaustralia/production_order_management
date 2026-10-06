<?php

namespace App\Models\Rcpa;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;

/**
 * Open sales-order lines still to deliver, including customer email.
 *
 * Delivery confirmation reads this view. Invoiced history stays on VwSALESView.
 */
class VwOPENORDERSView extends ReadOnlyView
{
    protected $table = 'dbo.vwOPEN_ORDERS';

    protected $primaryKey = 'LINE_UNIQUE_ID';

    public $incrementing = false;

    /**
     * @var list<string>
     */
    public const EXCLUDED_STATUSES = ['C', 'S', 'X'];

    protected static function booted(): void
    {
        static::addGlobalScope('openOrder', function (Builder $query): void {
            self::excludeCancelled($query);
        });
    }

    /**
     * Lines that still have quantity left to deliver.
     */
    #[Scope]
    protected function outstanding(Builder $query): Builder
    {
        return $query->where('BACKORDER_QTY', '>', 0);
    }

    public static function excludeCancelled(object $query, string $headerStatus = 'STATUS_FLAG', string $lineStatus = 'LINE_STATUS'): void
    {
        $query
            ->whereNotIn($headerStatus, self::EXCLUDED_STATUSES)
            ->whereNotIn($lineStatus, self::EXCLUDED_STATUSES);
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
            'REFERENCE_DUPLICATE' => 'integer',
            'LINE_NBR' => 'integer',
            'LINE_UNIQUE_ID' => 'integer',
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
