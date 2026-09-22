<?php

namespace Tests\Feature;

use App\Enums\AppRole;
use App\Models\Depot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MakeUserCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_a_planner_admin_who_can_reach_the_panel(): void
    {
        $this->artisan('rcpa:make-user', [
            '--email' => 'planner@rcpa.com.au',
            '--name' => 'Planner',
            '--role' => [AppRole::PlannerAdmin->value],
        ])
            ->expectsQuestion('Password (input hidden)', 'a-long-enough-password')
            ->expectsQuestion('Confirm password', 'a-long-enough-password')
            ->assertSuccessful();

        $user = User::query()->where('email', 'planner@rcpa.com.au')->sole();

        $this->assertTrue(Hash::check('a-long-enough-password', $user->password));
        $this->assertTrue($user->hasAppRole(AppRole::PlannerAdmin));
        // No depot restriction means every plant, which is what a national planner needs.
        $this->assertFalse($user->depots()->exists());
    }

    public function test_restricts_a_user_to_named_plants(): void
    {
        Depot::factory()->create(['code' => 'SOM']);
        Depot::factory()->create(['code' => 'THO']);
        Depot::factory()->create(['code' => 'MEL']);

        $this->artisan('rcpa:make-user', [
            '--email' => 'nsw@rcpa.com.au',
            '--name' => 'NSW Planner',
            '--role' => [AppRole::DepotOperator->value],
            '--depot' => ['SOM', 'THO'],
        ])
            ->expectsQuestion('Password (input hidden)', 'a-long-enough-password')
            ->expectsQuestion('Confirm password', 'a-long-enough-password')
            ->assertSuccessful();

        $this->assertSame(
            ['SOM', 'THO'],
            User::query()->where('email', 'nsw@rcpa.com.au')->sole()
                ->depots()->orderBy('code')->pluck('code')->all(),
        );
    }

    public function test_updates_an_existing_user_rather_than_duplicating(): void
    {
        User::factory()->create(['email' => 'planner@rcpa.com.au', 'name' => 'Old Name']);

        $this->artisan('rcpa:make-user', [
            '--email' => 'planner@rcpa.com.au',
            '--name' => 'New Name',
            '--role' => [AppRole::PlannerAdmin->value],
        ])
            ->expectsQuestion('Password (input hidden)', 'a-long-enough-password')
            ->expectsQuestion('Confirm password', 'a-long-enough-password')
            ->assertSuccessful();

        $this->assertSame(1, User::query()->where('email', 'planner@rcpa.com.au')->count());
        $this->assertSame('New Name', User::query()->where('email', 'planner@rcpa.com.au')->value('name'));
    }

    public function test_rejects_an_unknown_role(): void
    {
        $this->artisan('rcpa:make-user', [
            '--email' => 'planner@rcpa.com.au',
            '--name' => 'Planner',
            '--role' => ['Not.A.Role'],
        ])->assertExitCode(2);

        $this->assertSame(0, User::query()->count());
    }

    public function test_rejects_a_short_password(): void
    {
        $this->artisan('rcpa:make-user', [
            '--email' => 'planner@rcpa.com.au',
            '--name' => 'Planner',
            '--role' => [AppRole::PlannerAdmin->value],
        ])
            ->expectsQuestion('Password (input hidden)', 'short')
            ->assertExitCode(2);

        $this->assertSame(0, User::query()->count());
    }

    public function test_rejects_a_mismatched_confirmation(): void
    {
        $this->artisan('rcpa:make-user', [
            '--email' => 'planner@rcpa.com.au',
            '--name' => 'Planner',
            '--role' => [AppRole::PlannerAdmin->value],
        ])
            ->expectsQuestion('Password (input hidden)', 'a-long-enough-password')
            ->expectsQuestion('Confirm password', 'a-different-password')
            ->assertExitCode(2);

        $this->assertSame(0, User::query()->count());
    }
}
