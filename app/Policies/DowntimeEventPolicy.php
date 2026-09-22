<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\DowntimeEvent;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class DowntimeEventPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:DowntimeEvent');
    }

    public function view(AuthUser $authUser, DowntimeEvent $downtimeEvent): bool
    {
        return $authUser->can('View:DowntimeEvent');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:DowntimeEvent');
    }

    public function update(AuthUser $authUser, DowntimeEvent $downtimeEvent): bool
    {
        return $authUser->can('Update:DowntimeEvent');
    }

    public function delete(AuthUser $authUser, DowntimeEvent $downtimeEvent): bool
    {
        return $authUser->can('Delete:DowntimeEvent');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:DowntimeEvent');
    }

    public function restore(AuthUser $authUser, DowntimeEvent $downtimeEvent): bool
    {
        return $authUser->can('Restore:DowntimeEvent');
    }

    public function forceDelete(AuthUser $authUser, DowntimeEvent $downtimeEvent): bool
    {
        return $authUser->can('ForceDelete:DowntimeEvent');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:DowntimeEvent');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:DowntimeEvent');
    }

    public function replicate(AuthUser $authUser, DowntimeEvent $downtimeEvent): bool
    {
        return $authUser->can('Replicate:DowntimeEvent');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:DowntimeEvent');
    }
}
