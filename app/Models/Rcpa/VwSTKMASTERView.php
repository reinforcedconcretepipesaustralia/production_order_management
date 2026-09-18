<?php

namespace App\Models\Rcpa;

class VwSTKMASTERView extends ReadOnlyView
{
    protected $table = 'dbo.vwSTK_MASTER';

    protected $primaryKey = 'ENTITY';

    public $incrementing = false;

    protected function casts(): array
    {
        return [
            'UNIT_WEIGHT' => 'decimal:2',
            'SALES_CONVERSION' => 'decimal:2',
            'STANDARD_COST' => 'decimal:2',
        ];
    }
}
