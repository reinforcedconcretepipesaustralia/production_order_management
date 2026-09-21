<?php

namespace Database\Factories;

use App\Models\Depot;
use App\Models\MaterialRequirement;
use App\Models\PlanRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MaterialRequirement>
 */
class MaterialRequirementFactory extends Factory
{
    protected $model = MaterialRequirement::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'plan_run_id' => PlanRun::factory(),
            'depot_id' => Depot::factory(),
            'week_starting' => now()->startOfWeek()->toDateString(),
            'kind' => MaterialRequirement::KIND_WIRE,
            'material_code' => 'WIREPL6.23',
            'unit' => 'kg',
            'quantity' => $this->faker->randomFloat(4, 100, 5000),
            'planned_pipes' => $this->faker->numberBetween(10, 500),
        ];
    }

    public function concrete(): static
    {
        return $this->state(fn (): array => [
            'kind' => MaterialRequirement::KIND_CONCRETE,
            'material_code' => 'MEL-BIDI',
            'unit' => 'm3',
        ]);
    }
}
