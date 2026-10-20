<?php

namespace Tests\Feature;

use App\Enums\AppRole;
use App\Filament\Pages\LogisticsLoadBuilder;
use App\Models\Depot;
use App\Models\DispatchLoad;
use App\Models\OrderAllocation;
use App\Models\SkuMaster;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class LogisticsLoadBuilderErpAllocationTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_load_builder_adds_erp_keyed_allocation_without_sales_order(): void
    {
        $depot = Depot::factory()->create(['code' => 'YAT']);
        $dispatcher = User::factory()->withAppRoles(AppRole::LogisticsDispatcher)->create();
        $dispatcher->depots()->attach($depot);
        $sku = SkuMaster::factory()->create(['unit_weight_tonnes' => 1.0]);
        $allocation = OrderAllocation::factory()->create([
            'sales_order_id' => null,
            'inventory_soh_id' => null,
            'production_schedule_id' => null,
            'depot_id' => $depot->id,
            'sku_master_id' => $sku->id,
            'erp_entity_code' => 'RCPQLD',
            'erp_order_reference' => '114042',
            'erp_line_reference' => '9001',
            'stock_location' => '40',
            'quantity' => 5,
            'status' => 'allocated',
        ]);
        $load = DispatchLoad::factory()->create([
            'depot_id' => $depot->id,
            'capacity_tonnes' => 10,
        ]);

        Livewire::actingAs($dispatcher)
            ->test(LogisticsLoadBuilder::class)
            ->set('dispatchLoadId', $load->id)
            ->set('allocationId', $allocation->id)
            ->set('quantity', 2)
            ->call('addAllocation')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('dispatch_load_items', [
            'dispatch_load_id' => $load->id,
            'order_allocation_id' => $allocation->id,
            'quantity' => 2,
        ]);
    }
}
