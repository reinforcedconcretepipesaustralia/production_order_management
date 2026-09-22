<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\NonProductionDay;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class NonProductionDayPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:NonProductionDay');
    }

    public function view(AuthUser $authUser, NonProductionDay $nonProductionDay): bool
    {
        return $authUser->can('View:NonProductionDay');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:NonProductionDay');
    }

    public function update(AuthUser $authUser, NonProductionDay $nonProductionDay): bool
    {
        return $authUser->can('Update:NonProductionDay');
    }

    public function delete(AuthUser $authUser, NonProductionDay $nonProductionDay): bool
    {
        return $authUser->can('Delete:NonProductionDay');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:NonProductionDay');
    }

    public function restore(AuthUser $authUser, NonProductionDay $nonProductionDay): bool
    {
        return $authUser->can('Restore:NonProductionDay');
    }

    public function forceDelete(AuthUser $authUser, NonProductionDay $nonProductionDay): bool
    {
        return $authUser->can('ForceDelete:NonProductionDay');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:NonProductionDay');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:NonProductionDay');
    }

    public function replicate(AuthUser $authUser, NonProductionDay $nonProductionDay): bool
    {
        return $authUser->can('Replicate:NonProductionDay');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:NonProductionDay');
    }
}
