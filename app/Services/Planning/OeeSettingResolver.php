<?php

namespace App\Services\Planning;

use App\Models\OeeSetting;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Finds the OEE settings in force for a depot, shift and machine on a given date.
 *
 * Settings are layered: a depot-wide row is the baseline, a shift row overrides it, and a
 * machine row overrides both — so a plant can hold one target while a single machine such
 * as the BiDi 84 is tracked separately. Rows are versioned by effective_from, so raising a
 * target next quarter does not rewrite the history the current plan was built against.
 *
 * Results are memoised because the planner resolves settings once per shift slot per day
 * across a six-month horizon, which is thousands of lookups for the same handful of rows.
 */
final class OeeSettingResolver
{
    /** @var array<string, OeeSetting|null> */
    private array $cache = [];

    public function resolve(
        int $depotId,
        ?int $shiftId,
        ?int $machineAssetId,
        DateTimeInterface $on,
    ): ?OeeSetting {
        $date = $on->format('Y-m-d');
        $key = implode('|', [$depotId, $shiftId ?? '-', $machineAssetId ?? '-', $date]);

        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        return $this->cache[$key] = OeeSetting::query()
            ->where('depot_id', $depotId)
            ->whereDate('effective_from', '<=', $date)
            ->where(fn (Builder $query) => $query
                ->whereNull('machine_asset_id')
                ->when($machineAssetId !== null, fn (Builder $inner) => $inner->orWhere('machine_asset_id', $machineAssetId)))
            ->where(fn (Builder $query) => $query
                ->whereNull('shift_id')
                ->when($shiftId !== null, fn (Builder $inner) => $inner->orWhere('shift_id', $shiftId)))
            ->mostSpecificFirst()
            ->first();
    }

    /**
     * Clears the memo. Call after seeding or editing settings inside a long-running process.
     */
    public function flush(): void
    {
        $this->cache = [];
    }
}
