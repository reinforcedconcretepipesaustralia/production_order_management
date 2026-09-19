<?php

namespace Database\Factories;

use App\Models\DumpReason;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DumpReason>
 */
class DumpReasonFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => (string) fake()->unique()->numberBetween(101, 199),
            'description' => fake()->randomElement([
                'Damaged Socket', 'Damaged Spigot', 'Circumferential crack',
                'Exposed reinforcement (Socket)', 'Boney Barrel',
            ]),
            'product_type' => DumpReason::TYPE_PIPE,
            'is_active' => true,
        ];
    }

    public function cage(): static
    {
        return $this->state(fn (): array => [
            'code' => (string) fake()->unique()->numberBetween(201, 299),
            'description' => fake()->randomElement(['Weak welds', 'Dimension out of spec', 'Wire out of spec']),
            'product_type' => DumpReason::TYPE_CAGE,
        ]);
    }
}
