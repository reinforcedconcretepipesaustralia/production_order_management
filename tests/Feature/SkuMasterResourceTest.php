<?php

namespace Tests\Feature;

use App\Enums\AppRole;
use App\Filament\Resources\SkuMasters\Pages\CreateSkuMaster;
use App\Filament\Resources\SkuMasters\Pages\EditSkuMaster;
use App\Filament\Resources\SkuMasters\SkuMasterResource;
use App\Models\SkuMaster;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SkuMasterResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_planner_can_create_a_product_with_bom_planning_fields(): void
    {
        $this->actingAs($this->planner());

        Livewire::test(CreateSkuMaster::class)
            ->fillForm([
                'sku_code' => 'R0600-4',
                'erp_entity_code' => 'VIC',
                'description' => '600mm FJ pipe',
                'mould_type' => 'FJ',
                'reo_cage' => 'single',
                'moulds_count' => 2,
                'pallets_count' => 25,
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('sku_master', [
            'sku_code' => 'R0600-4',
            'erp_entity_code' => 'VIC',
            'mould_type' => 'FJ',
            'reo_cage' => 'single',
            'moulds_count' => 2,
            'pallets_count' => 25,
        ]);
    }

    public function test_planner_can_change_mould_and_cage_fields(): void
    {
        $this->actingAs($this->planner());
        $sku = SkuMaster::factory()->create([
            'sku_code' => 'R0750-2',
            'erp_entity_code' => 'NSW',
            'mould_type' => 'RJ',
            'reo_cage' => 'single',
            'moulds_count' => 1,
            'pallets_count' => 10,
        ]);

        Livewire::test(EditSkuMaster::class, ['record' => $sku->getRouteKey()])
            ->fillForm([
                'mould_type' => 'FJ',
                'reo_cage' => 'double',
                'moulds_count' => 3,
                'pallets_count' => 18,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('sku_master', [
            'id' => $sku->id,
            'sku_code' => 'R0750-2',
            'erp_entity_code' => 'NSW',
            'mould_type' => 'FJ',
            'reo_cage' => 'double',
            'moulds_count' => 3,
            'pallets_count' => 18,
        ]);
    }

    public function test_the_same_sku_code_cannot_be_added_twice_for_one_entity(): void
    {
        $this->actingAs($this->planner());
        SkuMaster::factory()->create([
            'sku_code' => 'R0600-4',
            'erp_entity_code' => 'VIC',
        ]);

        Livewire::test(CreateSkuMaster::class)
            ->fillForm([
                'sku_code' => 'R0600-4',
                'erp_entity_code' => 'VIC',
                'description' => 'Duplicate',
            ])
            ->call('create')
            ->assertHasFormErrors(['sku_code']);
    }

    public function test_the_products_list_shows_bom_planning_columns(): void
    {
        $this->actingAs($this->planner());
        SkuMaster::factory()->create([
            'sku_code' => 'R0900-3',
            'erp_entity_code' => 'QLD',
            'mould_type' => 'FJ',
            'reo_cage' => 'double',
            'moulds_count' => 4,
            'pallets_count' => 12,
        ]);

        $this->get(SkuMasterResource::getUrl('index'))
            ->assertSuccessful()
            ->assertSee('Tencia code')
            ->assertSee('Mould type')
            ->assertSee('Reo cage')
            ->assertSee('Moulds #')
            ->assertSee('Pallets #')
            ->assertSee('R0900-3')
            ->assertSee('FJ')
            ->assertSee('Double');
    }

    private function planner(): User
    {
        return User::factory()->withAppRoles(AppRole::PlannerAdmin)->create();
    }
}
