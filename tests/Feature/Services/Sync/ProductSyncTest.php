<?php

namespace Tests\Feature\Services\Sync;

use App\Models\Depot;
use App\Models\SkuMaster;
use App\Services\Sync\ProductSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\FakeTenciaSource;
use Tests\TestCase;

class ProductSyncTest extends TestCase
{
    use RefreshDatabase;

    private FakeTenciaSource $tencia;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tencia = FakeTenciaSource::install()->createTables();

        Depot::factory()->create([
            'code' => 'MEL', 'erp_entity_code' => 'RCPVIC', 'erp_stock_categories' => ['VA', 'VD'],
        ]);
        Depot::factory()->create([
            'code' => 'SOM', 'erp_entity_code' => 'RCPNSW', 'erp_stock_categories' => ['NA'],
        ]);
    }

    public function test_imports_a_product_with_its_weight_and_diameter(): void
    {
        $this->tencia->addProduct([
            'STOCK_CODE' => 'R0600-4', 'STOCK_DESCRIPTION_1' => '600mm class 4 pipe', 'UNIT_WEIGHT' => 0.594,
        ]);

        $counts = app(ProductSync::class)->sync()->counts();

        $this->assertSame(1, $counts['products']);

        $sku = SkuMaster::query()->sole();

        $this->assertSame('R0600-4', $sku->sku_code);
        $this->assertSame('RCPVIC', $sku->erp_entity_code);
        $this->assertSame('600mm class 4 pipe', $sku->description);
        $this->assertSame(600, $sku->diameter_mm);
        $this->assertSame('0.5940', $sku->unit_weight_tonnes);
        $this->assertTrue($sku->is_active);
    }

    /**
     * A stock code only identifies a product within its entity: R0600-4 exists in both VIC and NSW
     * with different bills of material, so collapsing them would give one state the wrong steel.
     */
    public function test_the_same_code_in_two_states_is_two_products(): void
    {
        $this->tencia
            ->addProduct(['ENTITY' => 'RCPVIC', 'STOCK_CODE' => 'R0600-4'])
            ->addProduct(['ENTITY' => 'RCPNSW', 'STOCK_CODE' => 'R0600-4', 'STOCK_CATEGORY' => 'NA']);

        app(ProductSync::class)->sync();

        $this->assertSame(2, SkuMaster::query()->where('sku_code', 'R0600-4')->count());
        $this->assertSame(
            ['RCPNSW', 'RCPVIC'],
            SkuMaster::query()->orderBy('erp_entity_code')->pluck('erp_entity_code')->all(),
        );
    }

    public function test_a_product_retired_in_tencia_stops_being_planned(): void
    {
        $this->tencia
            ->addProduct(['STOCK_CODE' => 'R0300-4', 'OBSOLETE_STOCK' => 'Y'])
            ->addProduct(['STOCK_CODE' => 'R0375-4', 'DELETE_STOCK' => 'Y']);

        $counts = app(ProductSync::class)->sync()->counts();

        $this->assertSame(2, $counts['products (inactive in Tencia)']);
        $this->assertSame(0, SkuMaster::query()->where('is_active', true)->count());
    }

    /**
     * Which categories are pipes differs per state, and copying one state's list into another is the
     * bug that is live in the VIC workbook today.
     */
    public function test_only_the_categories_the_plant_declares_are_imported(): void
    {
        $this->tencia
            ->addProduct(['STOCK_CODE' => 'R0600-4', 'STOCK_CATEGORY' => 'VA'])
            ->addProduct(['STOCK_CODE' => 'RR0600', 'STOCK_CATEGORY' => 'VG']);

        app(ProductSync::class)->sync();

        $this->assertSame(['R0600-4'], SkuMaster::query()->pluck('sku_code')->all());
    }

    public function test_re_running_updates_rather_than_duplicates(): void
    {
        $this->tencia->addProduct(['STOCK_CODE' => 'R0600-4', 'UNIT_WEIGHT' => 0.5]);

        app(ProductSync::class)->sync();
        app(ProductSync::class)->sync();

        $this->assertSame(1, SkuMaster::query()->count());
    }

    /**
     * The workbook stopgap created products before entities were recorded. Those rows are adopted
     * once rather than duplicated alongside the synced copy.
     */
    public function test_a_product_loaded_before_entities_were_recorded_is_adopted(): void
    {
        $existing = SkuMaster::factory()->create([
            'sku_code' => 'R0600-4', 'erp_entity_code' => null, 'machine_preference' => 'BiDi 84',
        ]);

        $this->tencia->addProduct(['STOCK_CODE' => 'R0600-4']);

        app(ProductSync::class)->sync();

        $this->assertSame(1, SkuMaster::query()->count());
        $this->assertSame('RCPVIC', $existing->fresh()->erp_entity_code);
        $this->assertSame('BiDi 84', $existing->fresh()->machine_preference, 'Planner-set fields survive.');
    }

    public function test_a_plant_with_no_categories_is_reported_rather_than_silently_skipped(): void
    {
        Depot::query()->update(['erp_stock_categories' => []]);

        $result = app(ProductSync::class)->sync();

        $this->assertSame(1, $result->counts()['skipped (no plant mapped)']);
        $this->assertNotEmpty($result->rejects());
    }

    public function test_limiting_to_one_entity_leaves_the_others_alone(): void
    {
        $this->tencia
            ->addProduct(['ENTITY' => 'RCPVIC', 'STOCK_CODE' => 'R0600-4'])
            ->addProduct(['ENTITY' => 'RCPNSW', 'STOCK_CODE' => 'R0450-4', 'STOCK_CATEGORY' => 'NA']);

        app(ProductSync::class)->sync('RCPNSW');

        $this->assertSame(['R0450-4'], SkuMaster::query()->pluck('sku_code')->all());
    }
}
