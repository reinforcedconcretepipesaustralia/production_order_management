<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\MachineAsset;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class MachineAssetPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:MachineAsset');
    }

    public function view(AuthUser $authUser, MachineAsset $machineAsset): bool
    {
        return $authUser->can('View:MachineAsset');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:MachineAsset');
    }

    public function update(AuthUser $authUser, MachineAsset $machineAsset): bool
    {
        return $authUser->can('Update:MachineAsset');
    }

    public function delete(AuthUser $authUser, MachineAsset $machineAsset): bool
    {
        return $authUser->can('Delete:MachineAsset');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:MachineAsset');
    }

    public function restore(AuthUser $authUser, MachineAsset $machineAsset): bool
    {
        return $authUser->can('Restore:MachineAsset');
    }

    public function forceDelete(AuthUser $authUser, MachineAsset $machineAsset): bool
    {
        return $authUser->can('ForceDelete:MachineAsset');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:MachineAsset');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:MachineAsset');
    }

    public function replicate(AuthUser $authUser, MachineAsset $machineAsset): bool
    {
        return $authUser->can('Replicate:MachineAsset');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:MachineAsset');
    }
}
