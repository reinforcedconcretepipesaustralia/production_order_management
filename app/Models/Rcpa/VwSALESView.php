<?php

namespace App\Models\Rcpa;

/**
 * Invoiced pipe sales (and credit notes), not the future order book.
 *
 * TRANS_TYPE is DRINV for invoices and DRCDT for credits. Open orders still to
 * make are VwSALESORDERSView.
 */
class VwSALESView extends ReadOnlyView
{
    protected $table = 'dbo.vwSALES';

    protected $primaryKey = 'ENTITY';

    public $incrementing = false;

    protected function casts(): array
    {
        return [
            'DATE' => 'datetime',
            'QUANTITY' => 'decimal:2',
            'COST_VALUE' => 'decimal:2',
            'TAX_VALUE' => 'decimal:2',
            'NET_VALUE' => 'decimal:2',
            'UNIT_WEIGHT' => 'decimal:2',
            'Net_Value_Ex' => 'decimal:2',
            'Cost' => 'decimal:2',
            'Margin' => 'decimal:2',
            'Line_Weight' => 'decimal:2',
            'Sell_Ton' => 'decimal:2',
            'LineFrCost' => 'decimal:2',
        ];
    }
}
