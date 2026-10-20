<?php

namespace Tests\Feature;

use App\Models\DeliveryScheduleLine;
use App\Models\Depot;
use App\Models\SkuMaster;
use App\Models\TransportCarrier;
use App\Services\Logistics\DeliveryScheduleBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Fixtures\SeedsLiveOrderBook;
use Tests\TestCase;

class DeliveryScheduleBuilderTest extends TestCase
{
    use LazilyRefreshDatabase;
    use SeedsLiveOrderBook;

    public function test_refresh_preserves_manual_transport_and_notes(): void
    {
        $this->installLiveOrderBook();

        $depot = Depot::factory()->create([
            'erp_entity_code' => 'RCPVIC',
            'prime_stock_locations' => ['31'],
        ]);
        $sku = SkuMaster::factory()->create([
            'erp_entity_code' => 'RCPVIC',
            'unit_weight_tonnes' => 0.5,
        ]);

        $date = '2026-09-20';
        $this->liveOrder($depot, $sku, 4, $date, location: '31');
        $this->liveStock($depot, $sku, 4);

        $builder = app(DeliveryScheduleBuilder::class);
        $schedule = $builder->refresh($depot, CarbonImmutable::parse($date));

        $line = $schedule->lines->first();
        $this->assertNotNull($line);

        $carrier = TransportCarrier::factory()->create();
        $line->forceFill([
            'transport_carrier_id' => $carrier->id,
            'notes' => 'Call before delivery',
            'is_confirmed' => true,
        ])->save();

        $builder->refresh($depot, CarbonImmutable::parse($date), runReconcile: true);

        $line->refresh();

        $this->assertSame($carrier->id, $line->transport_carrier_id);
        $this->assertSame('Call before delivery', $line->notes);
        $this->assertTrue($line->is_confirmed);
        $this->assertSame(4, $line->dispatch_qty);
    }
}
