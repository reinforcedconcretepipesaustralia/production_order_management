<?php

namespace App\Services\Planning;

use App\Models\MaterialRequirement;
use App\Models\PlanRun;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * What one plan run needs, summarised for a buyer: totals by material, and by week.
 */
final readonly class MaterialForecast
{
    private const GRAMS_PER_KG = 1000;

    private const UNITS_PER_M3 = 10000;

    /**
     * @param  list<array{depot_id: int, week: CarbonImmutable, kind: string, code: string, unit: string, amount: int, pipes: int}>  $lines
     */
    public function __construct(
        public PlanRun $planRun,
        private array $lines,
    ) {}

    public function isEmpty(): bool
    {
        return $this->lines === [];
    }

    /**
     * Wire in kilograms per gauge, heaviest first — the order a buyer cares about.
     *
     * @return Collection<string, float>
     */
    public function wireByGauge(): Collection
    {
        return $this->totalsFor(MaterialRequirement::KIND_WIRE, self::GRAMS_PER_KG);
    }

    /**
     * @return Collection<string, float>
     */
    public function concreteByMix(): Collection
    {
        return $this->totalsFor(MaterialRequirement::KIND_CONCRETE, self::UNITS_PER_M3);
    }

    public function totalWireKg(): float
    {
        return round($this->wireByGauge()->sum(), 3);
    }

    public function totalWireTonnes(): float
    {
        return round($this->totalWireKg() / 1000, 3);
    }

    public function totalConcreteM3(): float
    {
        return round($this->concreteByMix()->sum(), 3);
    }

    /**
     * Wire kilograms per week, so a shortfall can be seen before the week it lands in.
     *
     * @return Collection<string, float>
     */
    public function wireKgByWeek(): Collection
    {
        return collect($this->lines)
            ->filter(fn (array $line): bool => $line['kind'] === MaterialRequirement::KIND_WIRE)
            ->groupBy(fn (array $line): string => $line['week']->toDateString())
            ->map(fn (Collection $group): float => round($group->sum('amount') / self::GRAMS_PER_KG, 3))
            ->sortKeys();
    }

    /**
     * @return Collection<int, float> Keyed by depot id.
     */
    public function wireKgByDepot(): Collection
    {
        return collect($this->lines)
            ->filter(fn (array $line): bool => $line['kind'] === MaterialRequirement::KIND_WIRE)
            ->groupBy('depot_id')
            ->map(fn (Collection $group): float => round($group->sum('amount') / self::GRAMS_PER_KG, 3));
    }

    /**
     * @return Collection<string, float>
     */
    private function totalsFor(string $kind, int $divisor): Collection
    {
        return collect($this->lines)
            ->filter(fn (array $line): bool => $line['kind'] === $kind)
            ->groupBy('code')
            ->map(fn (Collection $group): float => round($group->sum('amount') / $divisor, 3))
            ->sortDesc();
    }
}
