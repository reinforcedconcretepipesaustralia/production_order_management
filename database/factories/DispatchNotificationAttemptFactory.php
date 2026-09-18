<?php

namespace Database\Factories;

use App\Models\DispatchLoad;
use App\Models\DispatchNotificationAttempt;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<DispatchNotificationAttempt>
 */
class DispatchNotificationAttemptFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'dispatch_load_id' => DispatchLoad::factory(),
            'provider' => 'microsoft-graph',
            'event_type' => 'dispatch-load-ready',
            'recipients' => [fake()->safeEmail()],
            'status' => 'queued',
            'attempt_count' => 0,
            'idempotency_key' => hash('sha256', Str::uuid()->toString()),
            'actor_user_id' => User::factory(),
        ];
    }
}
