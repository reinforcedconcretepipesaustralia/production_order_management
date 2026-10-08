<?php

namespace Tests\Feature\Services\Planning;

use App\Models\SchedulingRule;
use App\Services\Planning\SchedulingRuleResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SchedulingRuleResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_plan_defaults_come_from_active_rules(): void
    {
        SchedulingRule::query()->where('code', SchedulingRule::CODE_MAX_RUNS_PER_SHIFT)->update(['value' => 1]);
        SchedulingRule::query()->where('code', SchedulingRule::CODE_MINIMUM_RUN_FRACTION)->update(['value' => 0.5]);
        SchedulingRule::query()->where('code', SchedulingRule::CODE_TARGET_MONTHS_ON_HAND)->update(['value' => 2]);
        SchedulingRule::query()->where('code', SchedulingRule::CODE_INCLUDE_REPLENISHMENT)->update(['value' => 0]);

        $defaults = app(SchedulingRuleResolver::class)->planDefaults();

        $this->assertSame(1, $defaults['max_runs_per_shift']);
        $this->assertSame(0.5, $defaults['minimum_run_fraction']);
        $this->assertSame(2.0, $defaults['target_months_on_hand']);
        $this->assertFalse($defaults['include_replenishment']);
    }

    public function test_inactive_rules_are_ignored(): void
    {
        SchedulingRule::query()->where('code', SchedulingRule::CODE_MAX_RUNS_PER_SHIFT)->update([
            'value' => 4,
            'is_active' => false,
        ]);

        $defaults = app(SchedulingRuleResolver::class)->planDefaults();

        $this->assertSame(2, $defaults['max_runs_per_shift']);
    }

    public function test_invalid_engine_values_fall_back_so_generate_does_not_crash(): void
    {
        SchedulingRule::query()->where('code', SchedulingRule::CODE_MAX_RUNS_PER_SHIFT)->update(['value' => 0]);
        SchedulingRule::query()->where('code', SchedulingRule::CODE_MINIMUM_RUN_FRACTION)->update(['value' => 2]);
        SchedulingRule::query()->where('code', SchedulingRule::CODE_TARGET_MONTHS_ON_HAND)->update(['value' => -1]);

        $defaults = app(SchedulingRuleResolver::class)->planDefaults();

        $this->assertSame(2, $defaults['max_runs_per_shift']);
        $this->assertSame(0.25, $defaults['minimum_run_fraction']);
        $this->assertSame(1.0, $defaults['target_months_on_hand']);
    }

    public function test_the_brief_lists_only_rules_the_schedulers_left_on(): void
    {
        SchedulingRule::query()->where('code', 'stay_on_product')->update(['is_active' => false]);

        $codes = collect(app(SchedulingRuleResolver::class)->activeBrief())->pluck('code');

        $this->assertFalse($codes->contains('stay_on_product'));
        $this->assertTrue($codes->contains(SchedulingRule::CODE_MAX_RUNS_PER_SHIFT));
    }
}
