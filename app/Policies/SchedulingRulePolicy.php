<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\SchedulingRule;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class SchedulingRulePolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:SchedulingRule');
    }

    public function view(AuthUser $authUser, SchedulingRule $schedulingRule): bool
    {
        return $authUser->can('View:SchedulingRule');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:SchedulingRule');
    }

    public function update(AuthUser $authUser, SchedulingRule $schedulingRule): bool
    {
        return $authUser->can('Update:SchedulingRule');
    }

    public function delete(AuthUser $authUser, SchedulingRule $schedulingRule): bool
    {
        return $authUser->can('Delete:SchedulingRule');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:SchedulingRule');
    }

    public function restore(AuthUser $authUser, SchedulingRule $schedulingRule): bool
    {
        return $authUser->can('Restore:SchedulingRule');
    }

    public function forceDelete(AuthUser $authUser, SchedulingRule $schedulingRule): bool
    {
        return $authUser->can('ForceDelete:SchedulingRule');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:SchedulingRule');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:SchedulingRule');
    }

    public function replicate(AuthUser $authUser, SchedulingRule $schedulingRule): bool
    {
        return $authUser->can('Replicate:SchedulingRule');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:SchedulingRule');
    }
}
