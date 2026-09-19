<?php

namespace Database\Factories;

use App\Models\DumpCause;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DumpCause>
 */
class DumpCauseFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'description' => fake()->unique()->randomElement([
                'BiDi set up/settings', 'Bridging in hopper', 'Broken machine',
                'Cage out of spec', 'Concrete strength', 'Dry mix', 'Operator error',
                'Poor compaction', 'Mould wear bands', 'Machine overfeed',
            ]),
            'category' => null,
            'is_active' => true,
        ];
    }
}
