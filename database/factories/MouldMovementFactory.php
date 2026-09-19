<?php

namespace Database\Factories;

use App\Models\Depot;
use App\Models\MouldAsset;
use App\Models\MouldMovement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MouldMovement>
 */
class MouldMovementFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'mould_asset_id' => MouldAsset::factory(),
            'from_depot_id' => Depot::factory(),
            'to_depot_id' => Depot::factory(),
            'dispatched_on' => null,
            'expected_on' => fake()->dateTimeBetween('now', '+1 month')->format('Y-m-d'),
            'received_on' => null,
            'status' => MouldMovement::STATUS_PLANNED,
            'notes' => null,
        ];
    }

    public function received(): static
    {
        return $this->state(fn (): array => [
            'status' => MouldMovement::STATUS_RECEIVED,
            'dispatched_on' => now()->subWeek()->toDateString(),
            'received_on' => now()->toDateString(),
        ]);
    }
}
