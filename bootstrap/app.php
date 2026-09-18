<?php

use App\Exceptions\CalculationInputException;
use App\Exceptions\InvalidOrderAllocationException;
use App\Exceptions\SchedulingConstraintException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        $exceptions->render(function (SchedulingConstraintException $exception, Request $request): ?JsonResponse {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'error' => [
                    'code' => 'scheduling_constraint',
                    'message' => 'The proposed schedule conflicts with an existing schedule.',
                    'details' => ['reasons' => $exception->reasons],
                ],
            ], 409);
        });
        $exceptions->render(function (InvalidOrderAllocationException $exception, Request $request): ?JsonResponse {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'error' => [
                    'code' => 'allocation_conflict',
                    'message' => 'The FIFO allocation could not be completed safely.',
                ],
            ], 409);
        });
        $exceptions->render(function (CalculationInputException $exception, Request $request): ?JsonResponse {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'error' => [
                    'code' => 'domain_validation_failed',
                    'message' => 'The calculation inputs conflict with domain requirements.',
                ],
            ], 422);
        });
    })->create();
