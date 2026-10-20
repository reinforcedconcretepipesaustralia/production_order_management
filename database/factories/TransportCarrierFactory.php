<?php

namespace Database\Factories;

use App\Models\TransportCarrier;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<TransportCarrier>
 */
class TransportCarrierFactory extends Factory
{
    protected $model = TransportCarrier::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'code' => Str::upper(Str::slug($name, '_')),
            'name' => $name,
            'phone' => fake()->optional()->phoneNumber(),
            'is_active' => true,
            'is_pickup' => false,
        ];
    }

    public function pickup(): static
    {
        return $this->state(fn (): array => ['is_pickup' => true]);
    }
}
