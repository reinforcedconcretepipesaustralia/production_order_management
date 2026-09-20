<?php

namespace Tests\Feature\Services\Sync;

use App\Models\Depot;
use App\Models\SkuMaster;
use App\Models\SkuWireRequirement;
use App\Services\Sync\BomSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\FakeTenciaSource;
use Tests\TestCase;

class BomSyncTest extends TestCase
{
    use RefreshDatabase;

    private FakeTenciaSource $tencia;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tencia = FakeTenciaSource::install()->createTables();

        Depot::factory()->create([
            'code' => 'MEL', 'erp_entity_code' => 'RCPVIC', 'erp_stock_categories' => ['VA'],
        ]);
    }

    /**
     * Wire arrives in tonnes per pipe. Reading the quantity without its unit turns 4.387 kg of steel
     * into 0.004 kg, which is the difference between a correct order and none at all.
     */
    public function test_wire_in_tonnes_becomes_kilograms_per_pipe(): void
    {
        $sku = $this->sku('R0600-4');

        $this->tencia->addBomLine([
            'BILL_STOCK_CODE' => 'R0600-4', 'STOCK_CODE' => 'WIREPL6.23',
            'UNIT_OF_MEASURE' => 'TONN', 'BILL_ORDER_QTY' => 0.024359,
        ]);

        app(BomSync::class)->sync();

        $requirement = SkuWireRequirement::query()->where('sku_master_id', $sku->id)->sole();

        $this->assertSame('WIREPL6.23', $requirement->wire_code);
        $this->assertSame('6.23', $requirement->wire_gauge_mm);
        $this->assertSame('24.359', $requirement->kg_per_pipe);
    }

    public function test_a_pipe_carries_every_gauge_its_bill_lists(): void
    {
        $this->sku('R0600-4');

        $this->tencia
            ->addBomLine(['BILL_STOCK_CODE' => 'R0600-4', 'STOCK_CODE' => 'WIREPL4.95', 'BILL_ORDER_QTY' => 0.004387])
            ->addBomLine(['BILL_STOCK_CODE' => 'R0600-4', 'STOCK_CODE' => 'WIREPL6.23', 'BILL_ORDER_QTY' => 0.024359]);

        app(BomSync::class)->sync();

        $this->assertSame(
            ['WIREPL4.95' => '4.387', 'WIREPL6.23' => '24.359'],
            SkuWireRequirement::query()->orderBy('wire_code')->pluck('kg_per_pipe', 'wire_code')->all(),
        );
    }

    public function test_the_same_gauge_on_two_lines_is_summed(): void
    {
        $this->sku('R0600-4');

        $this->tencia
            ->addBomLine(['BILL_STOCK_CODE' => 'R0600-4', 'STOCK_CODE' => 'WIREPL6.23', 'BILL_ORDER_QTY' => 0.01])
            ->addBomLine(['BILL_STOCK_CODE' => 'R0600-4', 'STOCK_CODE' => 'WIREPL6.23', 'BILL_ORDER_QTY' => 0.005]);

        app(BomSync::class)->sync();

        $this->assertSame('15.000', SkuWireRequirement::query()->sole()->kg_per_pipe);
    }

    public function test_the_concrete_mix_becomes_volume_per_pipe(): void
    {
        $sku = $this->sku('R0600-4');

        $this->tencia->addBomLine([
            'BILL_STOCK_CODE' => 'R0600-4', 'STOCK_CODE' => 'YAT-BIDI',
            'UNIT_OF_MEASURE' => 'M3', 'BILL_ORDER_QTY' => 0.308618,
        ]);

        app(BomSync::class)->sync();

        $this->assertSame('0.3086', $sku->fresh()->concrete_volume_m3);
    }

    /**
     * Labour and overhead recoveries share the tonnes unit with wire but are not materials.
     */
    public function test_labour_and_overhead_are_not_treated_as_steel(): void
    {
        $this->sku('R0600-4');

        $this->tencia
            ->addBomLine(['BILL_STOCK_CODE' => 'R0600-4', 'STOCK_CODE' => 'LAB - MEL PIPES', 'BILL_ORDER_QTY' => 0.0541])
            ->addBomLine(['BILL_STOCK_CODE' => 'R0600-4', 'STOCK_CODE' => 'OH - MEL PIPES', 'BILL_ORDER_QTY' => 0.0541]);

        app(BomSync::class)->sync();

        $this->assertSame(0, SkuWireRequirement::query()->count());
    }

    public function test_a_gauge_dropped_from_a_design_stops_being_ordered(): void
    {
        $sku = $this->sku('R0600-4');

        SkuWireRequirement::factory()->create([
            'sku_master_id' => $sku->id, 'wire_code' => 'WIREPL3.97', 'kg_per_pipe' => 5,
        ]);

        $this->tencia->addBomLine([
            'BILL_STOCK_CODE' => 'R0600-4', 'STOCK_CODE' => 'WIREPL6.23', 'BILL_ORDER_QTY' => 0.02,
        ]);

        app(BomSync::class)->sync();

        $this->assertSame(['WIREPL6.23'], SkuWireRequirement::query()->pluck('wire_code')->all());
    }

    public function test_a_bill_for_a_product_this_plant_does_not_plan_is_counted_not_imported(): void
    {
        $this->sku('R0600-4');

        $this->tencia->addBomLine([
            'BILL_STOCK_CODE' => 'PRECAST-1', 'STOCK_CODE' => 'WIREPL6.23', 'BILL_ORDER_QTY' => 0.02,
        ]);

        $counts = app(BomSync::class)->sync()->counts();

        $this->assertSame(1, $counts['skipped (product not planned)']);
        $this->assertSame(0, SkuWireRequirement::query()->count());
    }

    public function test_a_wire_line_in_an_unexpected_unit_is_reported_not_guessed(): void
    {
        $this->sku('R0600-4');

        $this->tencia->addBomLine([
            'BILL_STOCK_CODE' => 'R0600-4', 'STOCK_CODE' => 'WIREPL6.23',
            'UNIT_OF_MEASURE' => 'EACH', 'BILL_ORDER_QTY' => 24,
        ]);

        $result = app(BomSync::class)->sync();

        $this->assertSame(1, $result->counts()['skipped (unexpected wire unit)']);
        $this->assertSame(0, SkuWireRequirement::query()->count());
        $this->assertNotEmpty($result->rejects());
    }

    /**
     * A holding yard makes nothing, so Tencia holds no bill for it and that is not a failure.
     */
    public function test_a_holding_yard_is_not_reported_as_missing_a_bill(): void
    {
        $this->sku('R0600-4');

        $result = app(BomSync::class)->sync();

        $this->assertArrayNotHasKey('skipped (no products for entity)', $result->counts());
        $this->assertStringNotContainsString('RCPSA', implode(' ', $result->rejects()));
    }

    private function sku(string $code): SkuMaster
    {
        return SkuMaster::factory()->create([
            'sku_code' => $code, 'erp_entity_code' => 'RCPVIC', 'concrete_volume_m3' => 0,
        ]);
    }
}
