<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Shift;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class ShiftPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Shift');
    }

    public function view(AuthUser $authUser, Shift $shift): bool
    {
        return $authUser->can('View:Shift');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Shift');
    }

    public function update(AuthUser $authUser, Shift $shift): bool
    {
        return $authUser->can('Update:Shift');
    }

    public function delete(AuthUser $authUser, Shift $shift): bool
    {
        return $authUser->can('Delete:Shift');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:Shift');
    }

    public function restore(AuthUser $authUser, Shift $shift): bool
    {
        return $authUser->can('Restore:Shift');
    }

    public function forceDelete(AuthUser $authUser, Shift $shift): bool
    {
        return $authUser->can('ForceDelete:Shift');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Shift');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Shift');
    }

    public function replicate(AuthUser $authUser, Shift $shift): bool
    {
        return $authUser->can('Replicate:Shift');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Shift');
    }
}
