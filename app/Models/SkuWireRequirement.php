<?php

namespace App\Models;

use Database\Factories\SkuWireRequirementFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SkuWireRequirement extends Model
{
    /** @use HasFactory<SkuWireRequirementFactory> */
    use HasFactory;

    protected $fillable = ['sku_master_id', 'wire_code', 'wire_gauge_mm', 'kg_per_pipe'];

    protected function casts(): array
    {
        return [
            'wire_gauge_mm' => 'decimal:2',
            'kg_per_pipe' => 'decimal:3',
        ];
    }

    public function sku(): BelongsTo
    {
        return $this->belongsTo(SkuMaster::class, 'sku_master_id');
    }

    /**
     * Wire gauge in millimetres parsed from a Tencia wire stock code, e.g. WIREPL3.97 -> 3.97.
     * Returns null when the code does not carry a trailing gauge.
     */
    public static function gaugeFromWireCode(string $wireCode): ?float
    {
        if (preg_match('/(\d+(?:\.\d+)?)\s*$/', $wireCode, $matches) !== 1) {
            return null;
        }

        return (float) $matches[1];
    }
}
