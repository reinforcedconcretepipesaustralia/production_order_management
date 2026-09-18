<?php

use App\Http\Controllers\Api\V1\CapacityCalculationController;
use App\Http\Controllers\Api\V1\FifoAllocationController;
use App\Http\Controllers\Api\V1\MouldConstraintValidationController;
use App\Http\Controllers\Api\V1\SteelReplenishmentCalculationController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')
    ->name('api.v1.')
    ->middleware(['auth', 'throttle:60,1'])
    ->group(function (): void {
        Route::post('scheduling/capacity-calculations', CapacityCalculationController::class)
            ->name('scheduling.capacity-calculations.store');
        Route::post('scheduling/mould-constraint-validations', MouldConstraintValidationController::class)
            ->name('scheduling.mould-constraint-validations.store');
        Route::post('allocations/fifo', FifoAllocationController::class)
            ->name('allocations.fifo.store');
        Route::post('steel/replenishment-calculations', SteelReplenishmentCalculationController::class)
            ->name('steel.replenishment-calculations.store');
    });
