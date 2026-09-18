<?php

namespace Tests\Unit\Services\Scheduling;

use App\Models\SkuMaster;
use App\Models\SteelForecast;
use App\Services\Scheduling\SteelDemandLine;
use App\Services\Scheduling\SteelPlanningService;
use App\Services\Scheduling\SteelStockPosition;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class SteelPlanningServiceTest extends TestCase
{
    public function test_recommends_order_when_projection_is_below_two_week_average_safety_stock(): void
    {
        $sku = new SkuMaster(['wire_code' => 'WIRE-500L', 'wire_kg_per_pipe' => '25.500']);
        $line = new SteelDemandLine(1, $sku, 10);
        $forecast = new SteelForecast([
            'depot_id' => 1,
            'wire_code' => 'WIRE-500L',
            'on_hand_coils' => 12,
            'average_coil_weight_tonnes' => '0.0250',
            'on_order_coils' => 4,
            'transfer_in_coils' => 2,
            'transfer_out_coils' => 1,
            'safety_threshold_coils' => 0,
        ]);
        $stock = SteelStockPosition::fromForecast($forecast);

        $result = (new SteelPlanningService)->calculate([$line], $stock, [100, 200, 300]);

        $this->assertSame('WIRE-500L', $result->wireCode);
        $this->assertSame('255.000', $result->requiredKg);
        $this->assertSame('0.255000', $result->requiredTonnes);
        $this->assertSame('170.000', $result->projectedEndingKg);
        $this->assertSame('400.000', $result->safetyStockKg);
        $this->assertSame('230.000', $result->recommendedOrderKg);
        $this->assertSame('0.230000', $result->recommendedOrderTonnes);
    }

    public function test_returns_zero_recommendation_when_projection_meets_safety_stock(): void
    {
        $sku = new SkuMaster(['wire_code' => 'WIRE-500N', 'wire_kg_per_pipe' => '20.000']);
        $line = new SteelDemandLine(2, $sku, 5);
        $stock = new SteelStockPosition(
            depotId: 2,
            wireCode: 'WIRE-500N',
            onHandCoils: 5,
            averageCoilWeightTonnes: '0.1000',
            safetyThresholdCoils: 2,
        );

        $result = (new SteelPlanningService)->calculate([$line], $stock);

        $this->assertSame('400.000', $result->projectedEndingKg);
        $this->assertSame('0.000', $result->recommendedOrderKg);
        $this->assertSame('0.000000', $result->recommendedOrderTonnes);
    }

    public function test_rejects_demand_when_sku_wire_code_differs_from_forecast_group(): void
    {
        $sku = new SkuMaster(['wire_code' => 'WIRE-500L', 'wire_kg_per_pipe' => '20.000']);
        $line = new SteelDemandLine(2, $sku, 5);
        $stock = new SteelStockPosition(
            depotId: 2,
            wireCode: 'WIRE-500N',
            onHandCoils: 5,
            averageCoilWeightTonnes: '1.0000',
        );

        $this->expectException(InvalidArgumentException::class);

        (new SteelPlanningService)->calculate([$line], $stock);
    }
}
