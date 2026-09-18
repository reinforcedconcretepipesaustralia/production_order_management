<?php

namespace App\Filament\Concerns;

use App\Enums\AppRole;
use App\Enums\Capability;
use App\Models\Depot;
use App\Models\User;
use Filament\Facades\Filament;

trait HasOperationalDepotScope
{
    protected static function operationalUser(): ?User
    {
        $user = Filament::auth()->user();

        return $user instanceof User ? $user : null;
    }

    /**
     * @param  list<Capability>  $capabilities
     */
    protected static function canViewOperationalPage(array $capabilities): bool
    {
        $user = static::operationalUser();

        if ($user === null) {
            return false;
        }

        if ($user->hasAnyRole(AppRole::PlannerAdmin, AppRole::ViewerExecutive)) {
            return true;
        }

        return collect($capabilities)->contains(
            fn (Capability $capability): bool => $user->hasCapability($capability),
        ) && $user->depots()->exists();
    }

    /**
     * A null result means the user may view every depot.
     *
     * @return list<int>|null
     */
    protected function accessibleDepotIds(): ?array
    {
        $user = static::operationalUser();

        if ($user?->hasAnyRole(AppRole::PlannerAdmin, AppRole::ViewerExecutive)) {
            return null;
        }

        return $user?->depots()->pluck('depots.id')->map(
            fn (mixed $id): int => (int) $id,
        )->all() ?? [];
    }

    protected function canManageDepot(Capability $capability, Depot|int $depot): bool
    {
        $user = static::operationalUser();

        return $user?->hasRole(AppRole::PlannerAdmin)
            || $user?->hasCapabilityForDepot($capability, $depot) === true;
    }
}
