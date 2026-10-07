<?php

namespace Tests\Feature;

use App\Enums\AppRole;
use App\Filament\Resources\SchedulingRules\Pages\CreateSchedulingRule;
use App\Filament\Resources\SchedulingRules\Pages\EditSchedulingRule;
use App\Filament\Resources\SchedulingRules\SchedulingRuleResource;
use App\Models\SchedulingRule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SchedulingRuleResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_planner_can_add_a_written_scheduling_rule(): void
    {
        $this->actingAs($this->planner());

        Livewire::test(CreateSchedulingRule::class)
            ->fillForm([
                'name' => 'No RJ after FJ on BiDi 36',
                'code' => 'no_rj_after_fj_bidi36',
                'category' => 'sequencing',
                'value' => null,
                'sort_order' => 80,
                'is_active' => true,
                'description' => 'Do not change from flush joint to rubber ring on BiDi 36 in the same shift.',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('scheduling_rules', [
            'code' => 'no_rj_after_fj_bidi36',
            'category' => 'sequencing',
            'is_active' => true,
        ]);
    }

    public function test_planner_can_change_a_parameter_the_engine_reads(): void
    {
        $this->actingAs($this->planner());
        $rule = SchedulingRule::query()
            ->where('code', SchedulingRule::CODE_MAX_RUNS_PER_SHIFT)
            ->firstOrFail();

        Livewire::test(EditSchedulingRule::class, ['record' => $rule->getRouteKey()])
            ->fillForm([
                'name' => $rule->name,
                'code' => $rule->code,
                'category' => $rule->category,
                'value' => 1,
                'sort_order' => $rule->sort_order,
                'is_active' => true,
                'description' => $rule->description,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(1.0, (float) $rule->fresh()->value);
    }

    public function test_the_list_shows_the_seeded_planner_levers(): void
    {
        $this->actingAs($this->planner());

        $this->get(SchedulingRuleResource::getUrl('index'))
            ->assertSuccessful()
            ->assertSee('Max products per shift')
            ->assertSee('max_runs_per_shift')
            ->assertSee('Stay on the same product');
    }

    private function planner(): User
    {
        return User::factory()->withAppRoles(AppRole::PlannerAdmin)->create();
    }
}
