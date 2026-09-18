<?php

namespace Tests\Feature;

use App\Enums\AppRole;
use App\Enums\Capability;
use App\Models\Depot;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RbacAuthorizationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_defines_the_exact_entra_app_role_values(): void
    {
        $this->assertSame([
            'Planner.Admin',
            'Depot.Operator',
            'Quality.Inspector',
            'Logistics.Dispatcher',
            'Materials.Buyer',
            'Viewer.Executive',
        ], array_column(AppRole::cases(), 'value'));
    }

    /**
     * @param  list<string>  $expectedCapabilities
     */
    #[DataProvider('roleCapabilities')]
    public function test_each_role_grants_only_its_declared_capabilities(
        AppRole $role,
        array $expectedCapabilities,
    ): void {
        $user = User::factory()->withAppRoles($role)->create();
        $depot = $this->createDepot('ASSIGNED');
        $user->depots()->attach($depot);

        foreach (Capability::cases() as $capability) {
            $arguments = $capability->requiresDepotAssignment() ? [$depot] : [];

            $this->assertSame(
                in_array($capability->value, $expectedCapabilities, true),
                Gate::forUser($user)->allows($capability->value, $arguments),
                "{$role->value} returned an unexpected decision for {$capability->value}.",
            );
        }
    }

    /**
     * @return array<string, array{AppRole, list<string>}>
     */
    public static function roleCapabilities(): array
    {
        return [
            'planner administrator' => [
                AppRole::PlannerAdmin,
                [
                    'schedules.view',
                    'schedules.manage',
                    'assets.view',
                    'assets.manage',
                    'master-data.view',
                    'master-data.manage',
                    'dashboards.view',
                ],
            ],
            'depot operator' => [
                AppRole::DepotOperator,
                [
                    'depot-operations.view',
                    'production-actuals.view',
                    'production-actuals.enter',
                ],
            ],
            'quality inspector' => [
                AppRole::QualityInspector,
                [
                    'quality-inspections.view',
                    'quality.release.sign-off',
                    'quality.rework.sign-off',
                ],
            ],
            'logistics dispatcher' => [
                AppRole::LogisticsDispatcher,
                [
                    'dispatch-loads.view',
                    'dispatch-loads.manage',
                    'dispatch-dates.manage',
                    'dispatch-notifications.send',
                ],
            ],
            'materials buyer' => [
                AppRole::MaterialsBuyer,
                [
                    'materials.view',
                    'steel-purchasing.manage',
                    'inventory-transfers.manage',
                ],
            ],
            'executive viewer' => [
                AppRole::ViewerExecutive,
                [
                    'dashboards.view',
                ],
            ],
        ];
    }

    /**
     * @return array<string, array{AppRole, Capability}>
     */
    public static function depotScopedCapabilities(): array
    {
        return [
            'operator production entry' => [
                AppRole::DepotOperator,
                Capability::EnterProductionActuals,
            ],
            'quality release sign-off' => [
                AppRole::QualityInspector,
                Capability::SignOffQualityRelease,
            ],
            'logistics load management' => [
                AppRole::LogisticsDispatcher,
                Capability::ManageDispatchLoads,
            ],
            'buyer steel purchasing' => [
                AppRole::MaterialsBuyer,
                Capability::ManageSteelPurchasing,
            ],
        ];
    }

    #[DataProvider('depotScopedCapabilities')]
    public function test_operational_capabilities_require_an_explicit_depot_assignment(
        AppRole $role,
        Capability $capability,
    ): void {
        $user = User::factory()->withAppRoles($role)->create();
        $assignedDepot = $this->createDepot('ASSIGNED');
        $otherDepot = $this->createDepot('OTHER');
        $user->depots()->attach($assignedDepot);

        $this->assertTrue(Gate::forUser($user)->allows($capability->value, $assignedDepot));
        $this->assertFalse(Gate::forUser($user)->allows($capability->value, $otherDepot));
        $this->assertFalse(Gate::forUser($user)->allows($capability->value));
    }

    public function test_multiple_roles_merge_capabilities_without_expanding_depot_scope(): void
    {
        $user = User::factory()
            ->withAppRoles(AppRole::DepotOperator, AppRole::ViewerExecutive)
            ->create();
        $assignedDepot = $this->createDepot('ASSIGNED');
        $otherDepot = $this->createDepot('OTHER');
        $user->depots()->attach($assignedDepot);

        $this->assertTrue(Gate::forUser($user)->allows('dashboards.view'));
        $this->assertTrue(Gate::forUser($user)->allows('production-actuals.enter', $assignedDepot));
        $this->assertFalse(Gate::forUser($user)->allows('production-actuals.enter', $otherDepot));
        $this->assertFalse(Gate::forUser($user)->allows('schedules.manage'));
    }

    public function test_unknown_synced_role_values_are_ignored_and_grant_nothing(): void
    {
        $user = User::factory()->create([
            'app_roles' => ['Unknown.Role'],
        ]);

        $this->assertSame([], $user->assignedRoles());
        $this->assertSame([], $user->capabilities());
        $this->assertFalse($user->hasRole('Unknown.Role'));
        $this->assertFalse($user->hasCapability('unknown.capability'));
    }

    public function test_app_roles_are_persisted_as_json_and_cast_back_to_an_array(): void
    {
        $user = User::factory()
            ->withAppRoles(AppRole::QualityInspector, AppRole::MaterialsBuyer)
            ->create();

        $user->refresh();

        $this->assertSame([
            'Quality.Inspector',
            'Materials.Buyer',
        ], $user->app_roles);
        $this->assertTrue($user->hasAnyRole(
            AppRole::PlannerAdmin,
            AppRole::MaterialsBuyer,
        ));
    }

    public function test_multiple_local_users_can_have_null_entra_identity_fields(): void
    {
        $firstUser = User::factory()->create();
        $secondUser = User::factory()->create();

        $this->assertNull($firstUser->entra_object_id);
        $this->assertNull($firstUser->entra_tenant_id);
        $this->assertNull($secondUser->entra_object_id);
        $this->assertNull($secondUser->entra_tenant_id);
    }

    public function test_same_entra_object_id_is_allowed_in_different_tenants(): void
    {
        $objectId = '11111111-1111-4111-8111-111111111111';

        $firstUser = User::factory()
            ->withEntraIdentity($objectId, 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa')
            ->create();
        $secondUser = User::factory()
            ->withEntraIdentity($objectId, 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb')
            ->create();

        $this->assertNotSame($firstUser->entra_tenant_id, $secondUser->entra_tenant_id);
    }

    public function test_duplicate_entra_identity_in_the_same_tenant_is_rejected(): void
    {
        $objectId = '11111111-1111-4111-8111-111111111111';
        $tenantId = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
        User::factory()->withEntraIdentity($objectId, $tenantId)->create();

        $this->expectException(QueryException::class);

        User::factory()->withEntraIdentity($objectId, $tenantId)->create();
    }

    private function createDepot(string $code): Depot
    {
        return Depot::factory()->create([
            'code' => $code,
            'name' => "{$code} Depot",
            'state' => 'VIC',
            'timezone' => 'Australia/Melbourne',
            'is_active' => true,
        ]);
    }
}
