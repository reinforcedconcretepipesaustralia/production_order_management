<?php

namespace Database\Factories;

use App\Models\DispatchLoad;
use App\Models\DispatchLoadItem;
use App\Models\OrderAllocation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DispatchLoadItem> */
class DispatchLoadItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'dispatch_load_id' => DispatchLoad::factory(),
            'order_allocation_id' => OrderAllocation::factory(),
            'quantity' => 1,
            'weight_tonnes' => fake()->randomFloat(3, 0.5, 20),
        ];
    }
}
