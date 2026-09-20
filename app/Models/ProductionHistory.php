<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A production run as Tencia closed it. See the table's migration for why this is separate from
 * production_actuals.
 */
class ProductionHistory extends Model
{
    use HasFactory;

    protected $table = 'production_history';

    protected $fillable = [
        'depot_id', 'sku_master_id', 'erp_entity_code', 'erp_order_number', 'erp_order_duplicate',
        'sku_code', 'produced_on', 'good_quantity', 'rejected_quantity', 'tonnes_produced', 'status',
    ];

    protected function casts(): array
    {
        return [
            'produced_on' => 'date',
            'good_quantity' => 'integer',
            'rejected_quantity' => 'integer',
            'tonnes_produced' => 'decimal:4',
        ];
    }

    public function depot(): BelongsTo
    {
        return $this->belongsTo(Depot::class);
    }

    public function sku(): BelongsTo
    {
        return $this->belongsTo(SkuMaster::class, 'sku_master_id');
    }

    /**
     * The share of pipes made that had to be dumped, which is the quality term of OEE.
     */
    public function qualityPercent(): ?float
    {
        $made = $this->good_quantity + $this->rejected_quantity;

        return $made === 0 ? null : round($this->good_quantity / $made * 100, 2);
    }
}
