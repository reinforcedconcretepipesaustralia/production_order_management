<?php

namespace App\Filament\Concerns;

use App\Enums\AppRole;
use App\Enums\Capability;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;

/**
 * Access rules for the screens that configure how the plants run — depots, shifts, shift
 * hours, machines, OEE settings, shutdowns and downtime.
 *
 * Planner.Admin and Viewer.Executive see everything, as they do elsewhere in the panel.
 * Anyone else needs the view capability to read and the manage capability to change. Unlike
 * HasOperationalDepotScope::canViewOperationalPage, this does not insist on a depot
 * assignment, because these capabilities are national by definition — Capability
 * ::requiresDepotAssignment() returns false for all of them.
 */
trait ConfiguresPlantReferenceData
{
    /**
     * @return list<Capability>
     */
    abstract protected static function viewCapabilities(): array;

    abstract protected static function manageCapability(): Capability;

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        if ($user instanceof User && $user->hasAnyRole(AppRole::ViewerExecutive)) {
            return true;
        }

        return static::hasAnyReferenceCapability(
            [...static::viewCapabilities(), static::manageCapability()],
        );
    }

    public static function canCreate(): bool
    {
        return static::hasAnyReferenceCapability([static::manageCapability()]);
    }

    public static function canEdit(Model $record): bool
    {
        return static::hasAnyReferenceCapability([static::manageCapability()]);
    }

    public static function canDelete(Model $record): bool
    {
        return static::hasAnyReferenceCapability([static::manageCapability()]);
    }

    public static function canDeleteAny(): bool
    {
        return static::hasAnyReferenceCapability([static::manageCapability()]);
    }

    /**
     * @param  list<Capability>  $capabilities
     */
    private static function hasAnyReferenceCapability(array $capabilities): bool
    {
        $user = Filament::auth()->user();

        if (! $user instanceof User) {
            return false;
        }

        if ($user->hasAnyRole(AppRole::PlannerAdmin)) {
            return true;
        }

        foreach ($capabilities as $capability) {
            if ($user->hasCapability($capability)) {
                return true;
            }
        }

        return false;
    }
}
