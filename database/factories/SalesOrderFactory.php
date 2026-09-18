<?php

namespace Database\Factories;

use App\Models\Depot;
use App\Models\SalesOrder;
use App\Models\SkuMaster;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SalesOrder>
 */
class SalesOrderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'depot_id' => Depot::factory(),
            'sku_master_id' => SkuMaster::factory(),
            'erp_order_reference' => fake()->unique()->bothify('SO-######'),
            'erp_line_reference' => fake()->numberBetween(1, 20),
            'customer_code' => fake()->bothify('CUST-####'),
            'customer_name' => fake()->company(),
            'delivery_address' => fake()->address(),
            'ordered_quantity' => fake()->numberBetween(1, 100),
            'allocated_quantity' => 0,
            'requested_delivery_date' => fake()->dateTimeBetween('+1 day', '+45 days')->format('Y-m-d'),
            'ordered_at' => fake()->dateTimeBetween('-30 days', 'now'),
            'status' => 'open',
        ];
    }
}
