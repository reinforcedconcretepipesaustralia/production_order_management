<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\SkuMaster;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class SkuMasterPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:SkuMaster');
    }

    public function view(AuthUser $authUser, SkuMaster $skuMaster): bool
    {
        return $authUser->can('View:SkuMaster');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:SkuMaster');
    }

    public function update(AuthUser $authUser, SkuMaster $skuMaster): bool
    {
        return $authUser->can('Update:SkuMaster');
    }

    public function delete(AuthUser $authUser, SkuMaster $skuMaster): bool
    {
        return $authUser->can('Delete:SkuMaster');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:SkuMaster');
    }

    public function restore(AuthUser $authUser, SkuMaster $skuMaster): bool
    {
        return $authUser->can('Restore:SkuMaster');
    }

    public function forceDelete(AuthUser $authUser, SkuMaster $skuMaster): bool
    {
        return $authUser->can('ForceDelete:SkuMaster');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:SkuMaster');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:SkuMaster');
    }

    public function replicate(AuthUser $authUser, SkuMaster $skuMaster): bool
    {
        return $authUser->can('Replicate:SkuMaster');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:SkuMaster');
    }
}
