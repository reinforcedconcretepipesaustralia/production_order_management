<?php

namespace Database\Factories;

use App\Models\SchedulingRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SchedulingRule>
 */
class SchedulingRuleFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->slug(3),
            'name' => fake()->sentence(3),
            'category' => fake()->randomElement(['capacity', 'inventory', 'sequencing', 'guidance']),
            'description' => fake()->sentence(),
            'value' => null,
            'sort_order' => 100,
            'is_active' => true,
        ];
    }
}
