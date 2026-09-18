<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\Capability;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Query\Builder;
use Illuminate\Validation\Rule;

class CalculateSteelReplenishmentRequest extends DepotScopedApiRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->isAuthorizedForDepot(Capability::ManageSteelPurchasing);
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
            'steel_forecast_id' => [
                'required',
                'integer',
                Rule::exists('steel_forecasts', 'id')->where(
                    fn (Builder $query): Builder => $query
                        ->where('depot_id', $this->integer('depot_id')),
                ),
            ],
            'demand_lines' => ['required', 'array', 'min:1'],
            'demand_lines.*.sku_id' => [
                'required',
                'integer',
                Rule::exists('sku_master', 'id')->where('is_active', true),
            ],
            'demand_lines.*.planned_quantity' => ['required', 'integer', 'min:0'],
            'rolling_weekly_consumption_kg' => ['sometimes', 'array', 'max:52'],
            'rolling_weekly_consumption_kg.*' => ['numeric', 'min:0'],
        ];
    }
}
