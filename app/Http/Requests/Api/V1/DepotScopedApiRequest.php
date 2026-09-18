<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\AppRole;
use App\Enums\Capability;
use App\Models\Depot;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

abstract class DepotScopedApiRequest extends FormRequest
{
    protected function isAuthorizedForDepot(Capability $depotCapability): bool
    {
        $user = $this->user();

        if ($user === null) {
            return false;
        }

        if (
            $user->hasRole(AppRole::PlannerAdmin)
            && Gate::forUser($user)->allows(Capability::ManageSchedules->value)
        ) {
            return true;
        }

        $depot = Depot::query()->find($this->integer('depot_id'));

        return $depot !== null
            && Gate::forUser($user)->allows($depotCapability->value, $depot);
    }
}
