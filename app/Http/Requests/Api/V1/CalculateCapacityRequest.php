<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\Capability;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Query\Builder;
use Illuminate\Validation\Rule;

class CalculateCapacityRequest extends DepotScopedApiRequest
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
            'shift_id' => [
                'required',
                'integer',
                Rule::exists('shifts', 'id')->where(
                    fn (Builder $query): Builder => $query
                        ->where('depot_id', $this->integer('depot_id'))
                        ->where('is_active', true),
                ),
            ],
            'sku_id' => [
                'required',
                'integer',
                Rule::exists('sku_master', 'id')->where('is_active', true),
            ],
        ];
    }
}
