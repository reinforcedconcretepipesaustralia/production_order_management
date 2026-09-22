<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\OeeSetting;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class OeeSettingPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:OeeSetting');
    }

    public function view(AuthUser $authUser, OeeSetting $oeeSetting): bool
    {
        return $authUser->can('View:OeeSetting');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:OeeSetting');
    }

    public function update(AuthUser $authUser, OeeSetting $oeeSetting): bool
    {
        return $authUser->can('Update:OeeSetting');
    }

    public function delete(AuthUser $authUser, OeeSetting $oeeSetting): bool
    {
        return $authUser->can('Delete:OeeSetting');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:OeeSetting');
    }

    public function restore(AuthUser $authUser, OeeSetting $oeeSetting): bool
    {
        return $authUser->can('Restore:OeeSetting');
    }

    public function forceDelete(AuthUser $authUser, OeeSetting $oeeSetting): bool
    {
        return $authUser->can('ForceDelete:OeeSetting');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:OeeSetting');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:OeeSetting');
    }

    public function replicate(AuthUser $authUser, OeeSetting $oeeSetting): bool
    {
        return $authUser->can('Replicate:OeeSetting');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:OeeSetting');
    }
}
