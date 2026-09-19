<?php

namespace Tests\Feature\Services\Planning;

use App\Models\Depot;
use App\Models\MachineAsset;
use App\Models\MachineSkuRunRate;
use App\Models\OeeSetting;
use App\Models\Shift;
use App\Models\SkuMaster;
use App\Services\Planning\OeeSettingResolver;
use App\Services\Planning\ResolvedRunRate;
use App\Services\Planning\RunRateResolver;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReferenceResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_oee_resolution_prefers_machine_then_shift_then_depot(): void
    {
        $depot = Depot::factory()->create();
        $shift = Shift::factory()->create(['depot_id' => $depot->id]);
        $machine = MachineAsset::factory()->create(['depot_id' => $depot->id]);
        $on = CarbonImmutable::parse('2026-09-07');

        $depotWide = OeeSetting::factory()->create([
            'depot_id' => $depot->id,
            'effective_from' => '2026-01-01',
            'availability_percent' => 50,
        ]);
        $resolver = app(OeeSettingResolver::class);

        $this->assertTrue($depotWide->is($resolver->resolve($depot->id, $shift->id, $machine->id, $on)));

        $shiftWide = OeeSetting::factory()->forShift($shift)->create([
            'effective_from' => '2026-01-01',
            'availability_percent' => 60,
        ]);
        $resolver->flush();

        $this->assertTrue($shiftWide->is($resolver->resolve($depot->id, $shift->id, $machine->id, $on)));

        $machineSpecific = OeeSetting::factory()->forMachine($machine)->create([
            'effective_from' => '2026-01-01',
            'availability_percent' => 70,
        ]);
        $resolver->flush();

        $this->assertTrue($machineSpecific->is($resolver->resolve($depot->id, $shift->id, $machine->id, $on)));
    }

    public function test_oee_resolution_uses_the_most_recent_setting_in_force(): void
    {
        $depot = Depot::factory()->create();
        $shift = Shift::factory()->create(['depot_id' => $depot->id]);

        OeeSetting::factory()->forShift($shift)->create([
            'effective_from' => '2026-07-01',
            'availability_percent' => 60,
        ]);
        $current = OeeSetting::factory()->forShift($shift)->create([
            'effective_from' => '2026-09-01',
            'availability_percent' => 85,
        ]);
        // A committed future target must not leak into today's plan.
        OeeSetting::factory()->forShift($shift)->create([
            'effective_from' => '2027-01-01',
            'availability_percent' => 90,
        ]);

        $resolved = app(OeeSettingResolver::class)
            ->resolve($depot->id, $shift->id, null, CarbonImmutable::parse('2026-09-07'));

        $this->assertTrue($current->is($resolved));
    }

    public function test_oee_resolution_ignores_settings_for_another_machine(): void
    {
        $depot = Depot::factory()->create();
        $shift = Shift::factory()->create(['depot_id' => $depot->id]);
        $bidi36 = MachineAsset::factory()->create(['depot_id' => $depot->id]);
        $bidi84 = MachineAsset::factory()->create(['depot_id' => $depot->id]);

        $shiftWide = OeeSetting::factory()->forShift($shift)->create(['effective_from' => '2026-01-01']);
        OeeSetting::factory()->forMachine($bidi84)->create([
            'effective_from' => '2026-01-01',
            'availability_percent' => 70,
        ]);

        $resolved = app(OeeSettingResolver::class)
            ->resolve($depot->id, $shift->id, $bidi36->id, CarbonImmutable::parse('2026-09-07'));

        $this->assertTrue($shiftWide->is($resolved));
    }

    public function test_oee_resolution_returns_null_when_nothing_is_configured(): void
    {
        $depot = Depot::factory()->create();

        $this->assertNull(
            app(OeeSettingResolver::class)->resolve($depot->id, null, null, CarbonImmutable::parse('2026-09-07')),
        );
    }

    public function test_run_rate_prefers_the_machine_specific_rate(): void
    {
        $machine = MachineAsset::factory()->create();
        $sku = SkuMaster::factory()->create(['standard_run_rate_per_hour' => 40]);
        MachineSkuRunRate::query()->create([
            'machine_asset_id' => $machine->id,
            'sku_master_id' => $sku->id,
            'rated_per_hour' => 32,
            'changeover_minutes' => 90,
        ]);

        $resolved = app(RunRateResolver::class)->resolve($machine, $sku);

        $this->assertSame('32.000', $resolved->ratePerHour);
        $this->assertSame(90, $resolved->changeoverMinutes);
        $this->assertSame(ResolvedRunRate::SOURCE_MACHINE, $resolved->source);
        $this->assertTrue($resolved->isMachineSpecific());
    }

    public function test_run_rate_applies_the_bom_adjustment_and_rounds_up(): void
    {
        $machine = MachineAsset::factory()->create();
        $sku = SkuMaster::factory()->create();
        // BOM column I is ROUNDUP(rated speed x adjustment, 0): 34 x 0.85 = 28.9 -> 29.
        MachineSkuRunRate::query()->create([
            'machine_asset_id' => $machine->id,
            'sku_master_id' => $sku->id,
            'rated_per_hour' => 34,
            'adjustment_factor' => 0.85,
        ]);

        $this->assertSame('29.000', app(RunRateResolver::class)->resolve($machine, $sku)->ratePerHour);
    }

    public function test_run_rate_falls_back_to_the_sku_standard_but_is_not_qualified(): void
    {
        $machine = MachineAsset::factory()->create();
        $sku = SkuMaster::factory()->create(['standard_run_rate_per_hour' => 25]);

        $resolver = app(RunRateResolver::class);
        $resolved = $resolver->resolve($machine, $sku);

        $this->assertSame('25.000', $resolved->ratePerHour);
        $this->assertSame(ResolvedRunRate::SOURCE_SKU_STANDARD, $resolved->source);
        // The planner must not assign work to a machine with no proven rate for the SKU.
        $this->assertFalse($resolver->isQualified($machine, $sku));
    }

    public function test_run_rate_is_null_when_no_rate_exists_at_all(): void
    {
        $machine = MachineAsset::factory()->create();
        $sku = SkuMaster::factory()->create(['standard_run_rate_per_hour' => 0]);

        $this->assertNull(app(RunRateResolver::class)->resolve($machine, $sku));
    }

    public function test_inactive_machine_rate_is_not_used(): void
    {
        $machine = MachineAsset::factory()->create();
        $sku = SkuMaster::factory()->create(['standard_run_rate_per_hour' => 25]);
        MachineSkuRunRate::query()->create([
            'machine_asset_id' => $machine->id,
            'sku_master_id' => $sku->id,
            'rated_per_hour' => 32,
            'is_active' => false,
        ]);

        $resolver = app(RunRateResolver::class);

        $this->assertSame('25.000', $resolver->resolve($machine, $sku)->ratePerHour);
        $this->assertFalse($resolver->isQualified($machine, $sku));
    }
}
