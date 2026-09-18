<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\Capability;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Query\Builder;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

class ValidateMouldConstraintRequest extends DepotScopedApiRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->isAuthorizedForDepot(Capability::ViewDepotOperations);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'depot_id' => ['required', 'integer', Rule::exists('depots', 'id')->where('is_active', true)],
            'shift_id' => ['required', 'integer', $this->existsAtDepot('shifts', 'depot_id')],
            'machine_asset_id' => ['required', 'integer', $this->existsAtDepot('machine_assets', 'depot_id')],
            'sku_id' => ['required', 'integer', Rule::exists('sku_master', 'id')->where('is_active', true)],
            'mould_asset_id' => ['required', 'integer', $this->existsAtDepot('mould_assets', 'current_depot_id')],
            'planned_start_at' => ['required', 'date'],
            'planned_end_at' => ['required', 'date', 'after:planned_start_at'],
            'planned_quantity' => ['required', 'integer', 'min:1'],
        ];
    }

    private function existsAtDepot(string $table, string $depotColumn): Exists
    {
        return Rule::exists($table, 'id')->where(
            fn (Builder $query): Builder => $query
                ->where($depotColumn, $this->integer('depot_id'))
                ->where('is_active', true),
        );
    }
}
