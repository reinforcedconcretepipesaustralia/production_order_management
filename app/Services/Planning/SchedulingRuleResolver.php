<?php

namespace App\Services\Planning;

use App\Models\SchedulingRule;

/**
 * Reads the schedulers' rule list so plan:generate uses their numbers, not buried defaults.
 *
 * Only the four codes the engine already understands change how a plan is built. Every other
 * active row is still handed to the narrator so the explanation stays aligned with the floor.
 */
final class SchedulingRuleResolver
{
    /**
     * @return array{
     *     target_months_on_hand: float,
     *     minimum_run_fraction: float,
     *     max_runs_per_shift: int,
     *     include_replenishment: bool
     * }
     */
    public function planDefaults(): array
    {
        return [
            'target_months_on_hand' => $this->number(
                SchedulingRule::CODE_TARGET_MONTHS_ON_HAND,
                DemandModel::DEFAULT_TARGET_MONTHS_ON_HAND,
                min: 0,
            ),
            'minimum_run_fraction' => $this->number(
                SchedulingRule::CODE_MINIMUM_RUN_FRACTION,
                0.25,
                min: 0.0001,
                max: 1,
            ),
            'max_runs_per_shift' => (int) $this->number(
                SchedulingRule::CODE_MAX_RUNS_PER_SHIFT,
                2,
                min: 1,
            ),
            'include_replenishment' => $this->number(SchedulingRule::CODE_INCLUDE_REPLENISHMENT, 1) > 0,
        ];
    }

    /**
     * @return list<array{code: string, name: string, category: string, value: float|null, description: string}>
     */
    public function activeBrief(): array
    {
        return SchedulingRule::query()
            ->active()
            ->get()
            ->map(fn (SchedulingRule $rule): array => [
                'code' => $rule->code,
                'name' => $rule->name,
                'category' => $rule->category,
                'value' => $rule->value === null ? null : (float) $rule->value,
                'description' => $rule->description,
            ])
            ->all();
    }

    private function number(string $code, float $fallback, ?float $min = null, ?float $max = null): float
    {
        $value = SchedulingRule::query()
            ->active()
            ->where('code', $code)
            ->value('value');

        if ($value === null) {
            return $fallback;
        }

        $number = (float) $value;

        if (($min !== null && $number < $min) || ($max !== null && $number > $max)) {
            return $fallback;
        }

        return $number;
    }
}
