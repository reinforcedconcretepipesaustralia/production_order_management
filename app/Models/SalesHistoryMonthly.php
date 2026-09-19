<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\SalesHistoryMonthlyFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesHistoryMonthly extends Model
{
    /** @use HasFactory<SalesHistoryMonthlyFactory> */
    use HasFactory;

    protected $table = 'sales_history_monthly';

    protected $fillable = [
        'depot_id', 'sku_master_id', 'month', 'quantity_ea', 'tonnes', 'net_value_ex_tax',
    ];

    protected function casts(): array
    {
        return [
            'month' => 'date',
            'quantity_ea' => 'integer',
            'tonnes' => 'decimal:4',
            'net_value_ex_tax' => 'decimal:2',
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
     * The trailing whole months before an as-of date, the way the workbook's velocity columns
     * window their SUMIFS: the current partial month is excluded so a half-finished month does
     * not drag an average down.
     */
    public function scopeTrailingMonths(Builder $query, \DateTimeInterface $asOf, int $months): Builder
    {
        $firstOfCurrentMonth = CarbonImmutable::instance($asOf)->startOfMonth();

        return $query
            ->where('month', '>=', $firstOfCurrentMonth->subMonths($months)->toDateString())
            ->where('month', '<', $firstOfCurrentMonth->toDateString());
    }
}
