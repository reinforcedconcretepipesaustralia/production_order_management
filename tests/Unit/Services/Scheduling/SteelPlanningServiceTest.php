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
        $sku = new SkuMaster(['sku_code' => 'R0600-4']);
        $line = new SteelDemandLine(1, $sku, 10, 'WIRE-500L', '25.500');
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
        $sku = new SkuMaster(['sku_code' => 'R0600-4']);
        $line = new SteelDemandLine(2, $sku, 5, 'WIRE-500N', '20.000');
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

    public function test_rejects_demand_when_the_gauge_differs_from_the_forecast_group(): void
    {
        $sku = new SkuMaster(['sku_code' => 'R0600-4']);
        $line = new SteelDemandLine(2, $sku, 5, 'WIRE-500L', '20.000');
        $stock = new SteelStockPosition(
            depotId: 2,
            wireCode: 'WIRE-500N',
            onHandCoils: 5,
            averageCoilWeightTonnes: '1.0000',
        );

        $this->expectException(InvalidArgumentException::class);

        (new SteelPlanningService)->calculate([$line], $stock);
    }

    /**
     * A pipe consumes several gauges, ordered separately. The single wire_code column on sku_master
     * only ever held one of them, understating every pipe with more than one gauge in its cage.
     */
    public function test_each_gauge_of_a_pipe_is_its_own_demand_line(): void
    {
        $sku = new SkuMaster(['sku_code' => 'R0600-4']);
        $stock = new SteelStockPosition(
            depotId: 1,
            wireCode: 'WIREPL6.23',
            onHandCoils: 0,
            averageCoilWeightTonnes: '1.0000',
            safetyThresholdCoils: 0,
        );

        $result = (new SteelPlanningService)->calculate(
            [
                new SteelDemandLine(1, $sku, 100, 'WIREPL6.23', '24.359'),
                new SteelDemandLine(1, $sku, 100, 'WIREPL6.23', '4.387'),
            ],
            $stock,
        );

        $this->assertSame('2874.600', $result->requiredKg);
    }

    public function test_a_line_without_a_gauge_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SteelDemandLine(1, new SkuMaster(['sku_code' => 'R0600-4']), 10, '', '5.000');
    }
}
