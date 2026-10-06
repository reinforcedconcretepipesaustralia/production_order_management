<?php

namespace App\Support\Authorization;

use App\Enums\AppRole;
use App\Enums\Capability;
use App\Filament\Pages\DeliveryReminders;
use App\Filament\Pages\ErpViews;
use App\Filament\Pages\LogisticsLoadBuilder;
use App\Filament\Pages\PlanReview;
use App\Filament\Pages\ProductionActuals;
use App\Filament\Pages\ProductionBoard;
use App\Filament\Pages\SteelRequisitionDashboard;
use App\Filament\Resources\Depots\DepotResource;
use App\Filament\Resources\DowntimeEvents\DowntimeEventResource;
use App\Filament\Resources\MachineAssets\MachineAssetResource;
use App\Filament\Resources\NonProductionDays\NonProductionDayResource;
use App\Filament\Resources\OeeSettings\OeeSettingResource;
use App\Filament\Resources\Shifts\ShiftResource;
use App\Models\User;
use BezhanSalleh\FilamentShield\Facades\FilamentShield;
use BezhanSalleh\FilamentShield\Support\Utils;
use Filament\Facades\Filament;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Keeps Filament Shield roles and permissions in step with the application roles.
 *
 * Each AppRole is a Shield role. Its capabilities are Shield custom permissions, and
 * the same role receives the resource and page permissions those capabilities unlock.
 */
class ShieldRoleCatalog
{
    /**
     * Plant screens whose view and manage permissions follow a capability.
     *
     * @var array<class-string, array{view: list<Capability>, manage: Capability}>
     */
    private const RESOURCES = [
        DepotResource::class => [
            'view' => [Capability::ViewMasterData],
            'manage' => Capability::ManageMasterData,
        ],
        ShiftResource::class => [
            'view' => [Capability::ViewMasterData, Capability::ViewSchedules],
            'manage' => Capability::ManageMasterData,
        ],
        MachineAssetResource::class => [
            'view' => [Capability::ViewAssets, Capability::ViewMasterData],
            'manage' => Capability::ManageAssets,
        ],
        OeeSettingResource::class => [
            'view' => [Capability::ViewMasterData, Capability::ViewSchedules],
            'manage' => Capability::ManageMasterData,
        ],
        NonProductionDayResource::class => [
            'view' => [Capability::ViewSchedules, Capability::ViewMasterData],
            'manage' => Capability::ManageSchedules,
        ],
        DowntimeEventResource::class => [
            'view' => [Capability::ViewSchedules, Capability::ViewAssets],
            'manage' => Capability::ManageSchedules,
        ],
    ];

    /**
     * Pages that are open to national roles, plus any role holding one of the capabilities.
     *
     * @var array<class-string, array{capabilities: list<Capability>, national: bool}>
     */
    private const PAGES = [
        ErpViews::class => [
            'capabilities' => [Capability::ViewMasterData],
            'national' => false,
        ],
        ProductionBoard::class => [
            'capabilities' => [Capability::ViewSchedules, Capability::ViewDepotOperations],
            'national' => true,
        ],
        PlanReview::class => [
            'capabilities' => [Capability::ViewSchedules, Capability::ManageSchedules, Capability::ViewDepotOperations],
            'national' => true,
        ],
        ProductionActuals::class => [
            'capabilities' => [Capability::ViewProductionActuals, Capability::EnterProductionActuals],
            'national' => false,
        ],
        LogisticsLoadBuilder::class => [
            'capabilities' => [Capability::ViewDispatchLoads],
            'national' => true,
        ],
        SteelRequisitionDashboard::class => [
            'capabilities' => [Capability::ViewMaterials],
            'national' => true,
        ],
        DeliveryReminders::class => [
            'capabilities' => [Capability::ViewDashboards, Capability::ViewSchedules],
            'national' => true,
        ],
    ];

    public function sync(): void
    {
        $panel = Filament::getCurrentPanel();
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $guard = Utils::getFilamentAuthGuard() ?: (string) config('auth.defaults.guard');

        foreach (Capability::cases() as $capability) {
            Permission::findOrCreate($capability->shieldPermission(), $guard);
        }

        foreach (AppRole::cases() as $role) {
            $permissions = $this->permissionsFor($role);

            foreach ($permissions as $permission) {
                Permission::findOrCreate($permission, $guard);
            }

            $shieldRole = Role::findOrCreate($role->value, $guard);
            $shieldRole->syncPermissions($permissions);
        }

        User::query()->each(function (User $user): void {
            $user->syncShieldRolesFromAppRoles();
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        if ($panel !== null) {
            Filament::setCurrentPanel($panel);
        }
    }

    /**
     * @return list<string>
     */
    public function permissionsFor(AppRole $role): array
    {
        $permissions = array_map(
            fn (Capability $capability): string => $capability->shieldPermission(),
            $role->capabilities(),
        );

        foreach (self::RESOURCES as $resource => $access) {
            $canManage = in_array($access['manage'], $role->capabilities(), true);
            $canView = $role === AppRole::ViewerExecutive
                || $canManage
                || $this->roleHasAny($role, $access['view']);

            if ($canView) {
                array_push($permissions, ...$this->resourcePermissionKeys($resource, manage: false));
            }

            if ($canManage) {
                array_push($permissions, ...$this->resourcePermissionKeys($resource, manage: true));
            }
        }

        foreach (self::PAGES as $page => $access) {
            $granted = ($access['national'] && $this->isNational($role))
                || $this->roleHasAny($role, $access['capabilities']);

            if (! $granted) {
                continue;
            }

            $permission = $this->pagePermissionKey($page);

            if ($permission !== null) {
                $permissions[] = $permission;
            }
        }

        return array_values(array_unique($permissions));
    }

    public function pagePermissionKey(string $page): ?string
    {
        $permissions = FilamentShield::getPages()[$page]['permissions'] ?? [];
        $permission = array_key_first($permissions);

        return is_string($permission) ? $permission : null;
    }

    /**
     * @param  list<Capability>  $capabilities
     */
    private function roleHasAny(AppRole $role, array $capabilities): bool
    {
        foreach ($capabilities as $capability) {
            if (in_array($capability, $role->capabilities(), true)) {
                return true;
            }
        }

        return false;
    }

    private function isNational(AppRole $role): bool
    {
        return in_array($role, [AppRole::PlannerAdmin, AppRole::ViewerExecutive], true);
    }

    /**
     * @return list<string>
     */
    private function resourcePermissionKeys(string $resource, bool $manage): array
    {
        $affixes = $manage
            ? ['create', 'update', 'delete', 'deleteAny']
            : ['viewAny', 'view'];

        $permissions = FilamentShield::getResources()[$resource]['permissions'] ?? [];

        return collect($permissions)
            ->only($affixes)
            ->pluck('key')
            ->filter(fn (mixed $key): bool => is_string($key))
            ->values()
            ->all();
    }
}
