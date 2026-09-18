<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\CalculationInputException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ValidateMouldConstraintRequest;
use App\Http\Resources\Api\V1\MouldConstraintValidationResource;
use App\Models\ProductionSchedule;
use App\Services\Scheduling\MouldConstraintService;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

class MouldConstraintValidationController extends Controller
{
    public function __invoke(
        ValidateMouldConstraintRequest $request,
        MouldConstraintService $constraints,
    ): MouldConstraintValidationResource {
        $validated = $request->validated();
        $candidate = new ProductionSchedule([
            'depot_id' => $validated['depot_id'],
            'shift_id' => $validated['shift_id'],
            'machine_asset_id' => $validated['machine_asset_id'],
            'sku_master_id' => $validated['sku_id'],
            'mould_asset_id' => $validated['mould_asset_id'],
            'schedule_date' => Carbon::parse($validated['planned_start_at'])->toDateString(),
            'planned_start_at' => $validated['planned_start_at'],
            'planned_end_at' => $validated['planned_end_at'],
            'planned_quantity' => $validated['planned_quantity'],
        ]);

        try {
            $constraints->assertNoConflicts($candidate);
            $changeover = $constraints->requiredChangeoverBlocks($candidate);
        } catch (InvalidArgumentException $exception) {
            throw new CalculationInputException($exception);
        }

        return new MouldConstraintValidationResource($changeover);
    }
}
