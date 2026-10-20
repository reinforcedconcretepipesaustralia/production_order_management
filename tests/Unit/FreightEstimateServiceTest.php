<?php

namespace Tests\Unit;

use App\Models\TransportCarrier;
use App\Models\TransportCarrierVehicle;
use App\Models\TransportCarrierZoneRate;
use App\Services\Logistics\FreightEstimateService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class FreightEstimateServiceTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_applies_semi_minimum_billable_tonnes_to_zone_rate(): void
    {
        $carrier = TransportCarrier::factory()->create();
        TransportCarrierVehicle::query()->create([
            'transport_carrier_id' => $carrier->id,
            'name' => 'Semi',
            'vehicle_kind' => 'semi',
            'capacity_tonnes' => 24,
            'minimum_billable_tonnes' => 20,
            'is_default' => true,
        ]);
        TransportCarrierZoneRate::query()->create([
            'erp_entity_code' => 'RCPQLD',
            'transport_carrier_id' => $carrier->id,
            'zone_code' => 'TODLZ4',
            'rate_per_tonne' => 10,
        ]);

        $estimate = app(FreightEstimateService::class)->estimate(
            'RCPQLD',
            'TODLZ4',
            $carrier,
            14,
        );

        $this->assertNotNull($estimate);
        $this->assertSame(14.0, $estimate->actualTonnes);
        $this->assertSame(20.0, $estimate->billableTonnes);
        $this->assertSame(200.0, $estimate->estimatedAmount);
    }

    public function test_pickup_carrier_returns_zero_freight(): void
    {
        $carrier = TransportCarrier::factory()->pickup()->create();

        $estimate = app(FreightEstimateService::class)->estimate(
            'RCPQLD',
            'ZZZNOFREIGH',
            $carrier,
            12,
        );

        $this->assertNotNull($estimate);
        $this->assertSame(0.0, $estimate->estimatedAmount);
    }
}
