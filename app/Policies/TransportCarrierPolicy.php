<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\TransportCarrier;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class TransportCarrierPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:TransportCarrier');
    }

    public function view(AuthUser $authUser, TransportCarrier $transportCarrier): bool
    {
        return $authUser->can('View:TransportCarrier');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:TransportCarrier');
    }

    public function update(AuthUser $authUser, TransportCarrier $transportCarrier): bool
    {
        return $authUser->can('Update:TransportCarrier');
    }

    public function delete(AuthUser $authUser, TransportCarrier $transportCarrier): bool
    {
        return $authUser->can('Delete:TransportCarrier');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:TransportCarrier');
    }

    public function restore(AuthUser $authUser, TransportCarrier $transportCarrier): bool
    {
        return $authUser->can('Restore:TransportCarrier');
    }

    public function forceDelete(AuthUser $authUser, TransportCarrier $transportCarrier): bool
    {
        return $authUser->can('ForceDelete:TransportCarrier');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:TransportCarrier');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:TransportCarrier');
    }

    public function replicate(AuthUser $authUser, TransportCarrier $transportCarrier): bool
    {
        return $authUser->can('Replicate:TransportCarrier');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:TransportCarrier');
    }
}
