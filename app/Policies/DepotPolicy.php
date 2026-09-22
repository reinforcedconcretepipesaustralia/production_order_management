<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Depot;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class DepotPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Depot');
    }

    public function view(AuthUser $authUser, Depot $depot): bool
    {
        return $authUser->can('View:Depot');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Depot');
    }

    public function update(AuthUser $authUser, Depot $depot): bool
    {
        return $authUser->can('Update:Depot');
    }

    public function delete(AuthUser $authUser, Depot $depot): bool
    {
        return $authUser->can('Delete:Depot');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:Depot');
    }

    public function restore(AuthUser $authUser, Depot $depot): bool
    {
        return $authUser->can('Restore:Depot');
    }

    public function forceDelete(AuthUser $authUser, Depot $depot): bool
    {
        return $authUser->can('ForceDelete:Depot');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Depot');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Depot');
    }

    public function replicate(AuthUser $authUser, Depot $depot): bool
    {
        return $authUser->can('Replicate:Depot');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Depot');
    }
}
