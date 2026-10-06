<?php

namespace Tests\Feature;

use App\Enums\AppRole;
use App\Enums\Capability;
use App\Filament\Pages\DeliveryReminders;
use App\Filament\Pages\ProductionBoard;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use App\Support\Authorization\ShieldRoleCatalog;
use BezhanSalleh\FilamentShield\Resources\Roles\RoleResource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class FilamentShieldSetupTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_open_roles_and_users_screens(): void
    {
        $user = User::factory()->create();
        $user->assignRole($this->superAdminRole());

        $this->actingAs($user);

        $this->assertTrue(RoleResource::canAccess());
        $this->assertTrue(UserResource::canAccess());

        $this->get(RoleResource::getUrl('index'))->assertSuccessful();
        $this->get(UserResource::getUrl('index'))->assertSuccessful();
    }

    public function test_planner_cannot_manage_shield_roles_or_users(): void
    {
        $user = User::factory()->withAppRoles(AppRole::PlannerAdmin)->create();

        $this->actingAs($user);

        $this->assertFalse(RoleResource::canAccess());
        $this->assertFalse(UserResource::canAccess());
    }

    public function test_application_roles_are_shield_roles_with_matching_permissions(): void
    {
        $planner = User::factory()->withAppRoles(AppRole::PlannerAdmin)->create();
        $executive = User::factory()->withAppRoles(AppRole::ViewerExecutive)->create();

        $this->assertTrue($planner->hasRole(AppRole::PlannerAdmin->value));
        $this->assertTrue($planner->hasCapability(Capability::ManageSchedules));
        $this->assertTrue($planner->can(Capability::ManageSchedules->shieldPermission()));
        $this->assertTrue($planner->can('ViewAny:Depot'));
        $this->assertTrue($planner->can('Create:Depot'));
        $this->assertTrue($planner->can('View:ErpViews'));

        $this->assertTrue($executive->hasRole(AppRole::ViewerExecutive->value));
        $this->assertTrue($executive->can('ViewAny:Depot'));
        $this->assertFalse($executive->can('Create:Depot'));
        $this->assertFalse($executive->hasCapability(Capability::ViewMasterData));
        $this->assertFalse($executive->can('View:ErpViews'));
        $this->assertTrue($executive->can(
            app(ShieldRoleCatalog::class)->pagePermissionKey(ProductionBoard::class),
        ));
        $this->assertTrue($executive->can(
            app(ShieldRoleCatalog::class)->pagePermissionKey(DeliveryReminders::class),
        ));

        $operator = User::factory()->withAppRoles(AppRole::DepotOperator)->create();
        $this->assertFalse($operator->can(
            app(ShieldRoleCatalog::class)->pagePermissionKey(DeliveryReminders::class),
        ));
    }

    public function test_spatie_roles_coexist_with_application_roles(): void
    {
        $user = User::factory()->withAppRoles(AppRole::DepotOperator)->create();
        $user->assignRole($this->role('panel_user'));

        $this->assertTrue($user->hasAppRole(AppRole::DepotOperator));
        $this->assertTrue($user->hasRole('panel_user'));
        $this->assertTrue($user->hasRole(AppRole::DepotOperator->value));
        $this->assertFalse($user->hasRole('super_admin'));
    }

    private function superAdminRole(): Role
    {
        return $this->role(config('filament-shield.super_admin.name'));
    }

    private function role(string $name): Role
    {
        return Role::findOrCreate($name, config('auth.defaults.guard'));
    }
}
