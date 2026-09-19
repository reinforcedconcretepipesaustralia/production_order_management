<?php

namespace Database\Factories;

use App\Models\PlanRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlanRun>
 */
class PlanRunFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'horizon_from' => now()->startOfMonth()->toDateString(),
            'horizon_to' => now()->startOfMonth()->addMonths(6)->toDateString(),
            'as_of_date' => now()->toDateString(),
            'depot_ids' => [],
            'status' => PlanRun::STATUS_DRAFT,
            'parameters' => [],
            'metrics' => null,
            'narrative' => null,
        ];
    }

    public function approved(): static
    {
        return $this->state(fn (): array => [
            'status' => PlanRun::STATUS_APPROVED,
            'approved_at' => now(),
        ]);
    }
}
