<?php

namespace App\Services\Planning;

use App\Models\MachineAsset;
use App\Models\MachineSkuRunRate;
use App\Models\SkuMaster;

/**
 * Decides how fast a machine runs a SKU.
 *
 * A machine_sku_run_rates row is authoritative and mirrors the BOM tab's adjusted rated
 * speed. Its absence means the machine is not qualified for that SKU, which is how the
 * planner avoids scheduling a 1800 mm pipe onto a machine that cannot take the mould. The
 * SKU's own standard_run_rate_per_hour remains as a fallback for display — the production
 * board still needs to show capacity for a line a planner entered by hand — but the planner
 * itself only assigns work where a machine-specific rate exists.
 */
final class RunRateResolver
{
    /** @var array<string, ResolvedRunRate|null> */
    private array $cache = [];

    public function resolve(MachineAsset $machine, SkuMaster $sku): ?ResolvedRunRate
    {
        $key = "{$machine->id}:{$sku->id}";

        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        $rate = MachineSkuRunRate::query()
            ->where('machine_asset_id', $machine->id)
            ->where('sku_master_id', $sku->id)
            ->where('is_active', true)
            ->first();

        if ($rate !== null) {
            return $this->cache[$key] = new ResolvedRunRate(
                ratePerHour: number_format($rate->effectiveRatePerHour(), 3, '.', ''),
                changeoverMinutes: (int) $rate->changeover_minutes,
                source: ResolvedRunRate::SOURCE_MACHINE,
            );
        }

        $standard = (float) $sku->standard_run_rate_per_hour;

        if ($standard <= 0) {
            return $this->cache[$key] = null;
        }

        return $this->cache[$key] = new ResolvedRunRate(
            ratePerHour: number_format($standard, 3, '.', ''),
            changeoverMinutes: 0,
            source: ResolvedRunRate::SOURCE_SKU_STANDARD,
        );
    }

    /**
     * Whether the planner may schedule this SKU on this machine.
     */
    public function isQualified(MachineAsset $machine, SkuMaster $sku): bool
    {
        return $this->resolve($machine, $sku)?->isMachineSpecific() ?? false;
    }

    public function flush(): void
    {
        $this->cache = [];
    }
}
