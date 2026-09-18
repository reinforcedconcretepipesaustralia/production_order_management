<?php

namespace App\Models;

use App\Enums\ProductionQualityStatus;
use App\Exceptions\InvalidProductionActualException;
use Carbon\CarbonImmutable;
use Database\Factories\ProductionActualFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductionActual extends Model
{
    /** @use HasFactory<ProductionActualFactory> */
    use HasFactory;

    protected $attributes = [
        'good_quantity' => 0,
        'dumped_quantity' => 0,
        'quality_percent' => 100,
        'quality_status' => ProductionQualityStatus::Curing->value,
    ];

    protected $fillable = [
        'production_schedule_id', 'manufactured_at', 'good_quantity', 'dumped_quantity',
        'dump_reason', 'actual_run_rate_per_hour', 'quality_percent', 'quality_status',
        'qc_released_at', 'qc_released_by', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'manufactured_at' => 'immutable_datetime',
            'actual_run_rate_per_hour' => 'decimal:3',
            'quality_percent' => 'decimal:2',
            'quality_status' => ProductionQualityStatus::class,
            'qc_released_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (ProductionActual $actual): void {
            if ($actual->manufactured_at === null) {
                throw new InvalidProductionActualException('Manufacture time is required.');
            }

            if ($actual->good_quantity < 0 || $actual->dumped_quantity < 0) {
                throw new InvalidProductionActualException('Production quantities cannot be negative.');
            }

            if ($actual->quality_percent < 0 || $actual->quality_percent > 100) {
                throw new InvalidProductionActualException('Quality percentage must be between 0 and 100.');
            }

            if ($actual->dumped_quantity > 0 && blank($actual->dump_reason)) {
                throw new InvalidProductionActualException('A dump reason is required when dumped quantity is greater than zero.');
            }

            if ($actual->quality_status === ProductionQualityStatus::Scrap && $actual->dumped_quantity <= 0) {
                throw new InvalidProductionActualException('Scrap status requires a dumped quantity.');
            }

            if ($actual->quality_status->isDispatchEligible()) {
                if ($actual->qc_released_at === null || blank($actual->qc_released_by)) {
                    throw new InvalidProductionActualException('Released or passed production requires QC release time and sign-off.');
                }

                $earliestReleaseAt = CarbonImmutable::parse($actual->manufactured_at)->addDays(7);
                if ($actual->qc_released_at->lt($earliestReleaseAt)) {
                    throw new InvalidProductionActualException('QC release cannot occur before seven calendar days of curing.');
                }
            }
        });
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(ProductionSchedule::class, 'production_schedule_id');
    }
}
