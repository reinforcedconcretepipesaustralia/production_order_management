<?php

namespace App\Filament\Concerns;

use App\Enums\AppRole;
use App\Enums\Capability;
use App\Models\Depot;
use App\Models\User;
use App\Support\Authorization\ShieldRoleCatalog;
use Filament\Facades\Filament;

trait HasOperationalDepotScope
{
    protected static function operationalUser(): ?User
    {
        $user = Filament::auth()->user();

        return $user instanceof User ? $user : null;
    }

    protected static function canViewOperationalPage(): bool
    {
        $user = static::operationalUser();

        if ($user === null) {
            return false;
        }

        if ($user->hasRole(config('filament-shield.super_admin.name'))) {
            return true;
        }

        $permission = app(ShieldRoleCatalog::class)->pagePermissionKey(static::class);

        if ($permission === null || ! $user->can($permission)) {
            return false;
        }

        if ($user->hasAnyRole(AppRole::PlannerAdmin->value, AppRole::ViewerExecutive->value)) {
            return true;
        }

        return $user->depots()->exists();
    }

    /**
     * A null result means the user may view every depot.
     *
     * @return list<int>|null
     */
    protected function accessibleDepotIds(): ?array
    {
        $user = static::operationalUser();

        if ($user?->hasAnyRole(AppRole::PlannerAdmin->value, AppRole::ViewerExecutive->value)) {
            return null;
        }

        return $user?->depots()->pluck('depots.id')->map(
            fn (mixed $id): int => (int) $id,
        )->all() ?? [];
    }

    protected function canManageDepot(Capability $capability, Depot|int $depot): bool
    {
        $user = static::operationalUser();

        return $user?->hasRole(AppRole::PlannerAdmin->value)
            || $user?->hasCapabilityForDepot($capability, $depot) === true;
    }
}
