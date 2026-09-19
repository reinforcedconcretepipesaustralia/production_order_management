<?php

namespace Tests\Feature\Services\Planning;

use App\Models\Depot;
use App\Models\DumpCause;
use App\Models\DumpReason;
use App\Models\Shift;
use App\Models\SkuMaster;
use App\Services\Planning\CapacityCalendarBuilder;
use App\Services\Planning\OeeSettingResolver;
use App\Services\Scheduling\ShiftCapacityCalculator;
use Carbon\CarbonImmutable;
use Database\Seeders\DepotSeeder;
use Database\Seeders\DumpCauseSeeder;
use Database\Seeders\DumpReasonSeeder;
use Database\Seeders\MachineAssetSeeder;
use Database\Seeders\OeeSettingSeeder;
use Database\Seeders\ShiftSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Guards the reference data transcribed from the three production planning workbooks. If a
 * seeded figure drifts, the plans built on it stop reconciling with what the plants actually
 * run, so these assertions are deliberately literal.
 */
class PlantReferenceDataSeedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            DepotSeeder::class,
            ShiftSeeder::class,
            MachineAssetSeeder::class,
            OeeSettingSeeder::class,
            DumpReasonSeeder::class,
            DumpCauseSeeder::class,
        ]);
    }

    public function test_seeds_a_pipe_machine_for_every_plant(): void
    {
        $machines = Depot::query()
            ->with('machines')
            ->orderBy('code')
            ->get()
            ->mapWithKeys(fn (Depot $depot): array => [$depot->code => $depot->machines->pluck('name')->sort()->values()->all()]);

        // Melton and Yatala run both BiDi machines; the other three run a single machine each.
        $this->assertSame(['BiDi 36', 'BiDi 84'], $machines['MEL']);
        $this->assertSame(['BiDi 36', 'BiDi 84'], $machines['YAT']);
        $this->assertSame(['Kilmore Pipe Machine'], $machines['KIL']);
        $this->assertSame(['Somersby Pipe Machine'], $machines['SOM']);
        $this->assertSame(['Thornton Pipe Machine'], $machines['THO']);
    }

    public function test_every_planning_enabled_plant_can_produce_capacity_slots(): void
    {
        $depots = Depot::query()
            ->with(['shifts.patterns', 'machines'])
            ->where('is_planning_enabled', true)
            ->get();

        // Monday 7 to Friday 11 September 2026.
        $slots = app(CapacityCalendarBuilder::class)->build(
            $depots,
            CarbonImmutable::parse('2026-09-07'),
            CarbonImmutable::parse('2026-09-11'),
        );

        $this->assertSame(
            $depots->pluck('code')->sort()->values()->all(),
            $slots->map(fn ($slot): string => $slot->depot->code)->unique()->sort()->values()->all(),
        );
        // 5 weekdays x (Melton 2 shifts x 2 machines + Kilmore 2 x 1 + Yatala 2 x 2
        // + Somersby 2 x 1 + Thornton 1 x 1) = 5 x 13 = 65 slots.
        $this->assertCount(65, $slots);
        $this->assertTrue($slots->every(fn ($slot): bool => $slot->hasCapacity()));
    }

    public function test_seeds_all_five_plants_across_three_states(): void
    {
        $depots = Depot::query()->orderBy('code')->get();

        $this->assertSame(['KIL', 'MEL', 'SOM', 'THO', 'YAT'], $depots->pluck('code')->all());
        $this->assertSame(['VIC', 'VIC', 'NSW', 'NSW', 'QLD'], $depots->pluck('state')->all());
        $this->assertTrue($depots->every(fn (Depot $depot): bool => $depot->is_planning_enabled));
    }

    public function test_maps_each_plant_to_its_tencia_entity_and_stock_locations(): void
    {
        $melton = Depot::query()->where('code', 'MEL')->sole();
        $somersby = Depot::query()->where('code', 'SOM')->sole();
        $yatala = Depot::query()->where('code', 'YAT')->sole();

        $this->assertSame('VIC', $melton->erp_entity_code);
        $this->assertSame(['VA', 'VB', 'VD'], $melton->erp_stock_categories);
        $this->assertSame(['MAIN', '31', '32', '35', '36'], $melton->prime_stock_locations);
        $this->assertSame(['33', '34', '37', '38'], $melton->rework_stock_locations);

        $this->assertSame('NSW', $somersby->erp_entity_code);
        $this->assertSame(['NA', 'NB', 'ND'], $somersby->erp_stock_categories);

        $this->assertSame('QLD', $yatala->erp_entity_code);
        $this->assertSame(['40'], $yatala->prime_stock_locations);
        $this->assertSame(['42'], $yatala->rework_stock_locations);
    }

    /**
     * Each plant's 'Effective hours' row on its OEE Settings tab, derived rather than typed:
     * shift length less prestart, breaks and cleaning.
     */
    #[DataProvider('effectiveHoursByPlant')]
    public function test_effective_hours_reconcile_with_the_workbooks(
        string $depotCode,
        string $shiftCode,
        int $durationMinutes,
        float $expectedEffectiveHours,
    ): void {
        $depot = Depot::query()->where('code', $depotCode)->sole();
        $shift = Shift::query()->where('depot_id', $depot->id)->where('code', $shiftCode)->sole();
        $setting = app(OeeSettingResolver::class)
            ->resolve($depot->id, $shift->id, null, CarbonImmutable::now());

        $this->assertNotNull($setting, "No OEE settings seeded for {$depotCode} {$shiftCode}.");
        $this->assertSame($durationMinutes, (int) $shift->duration_minutes);
        $this->assertSame(
            $expectedEffectiveHours,
            (float) (($durationMinutes - $setting->nonProductiveMinutes()) / 60),
        );
    }

    /**
     * @return array<string, array{string, string, int, float}>
     */
    public static function effectiveHoursByPlant(): array
    {
        return [
            'Melton dayshift' => ['MEL', 'DAY', 600, 9.0],
            'Melton afternoon' => ['MEL', 'AFT', 600, 9.0],
            'Kilmore dayshift' => ['KIL', 'DAY', 600, 9.0],
            'Kilmore afternoon' => ['KIL', 'AFT', 600, 9.0],
            'Yatala dayshift' => ['YAT', 'DAY', 600, 9.0],
            'Yatala afternoon' => ['YAT', 'AFT', 600, 9.0],
            'Somersby dayshift' => ['SOM', 'DAY', 600, 9.0],
            'Somersby afternoon' => ['SOM', 'AFT', 390, 5.75],
            'Thornton dayshift' => ['THO', 'DAY', 540, 7.25],
        ];
    }

    /**
     * Somersby's workbook collapses both crews into a single 16.5 hr block of 14.75 effective
     * hours. Splitting it into a day and an afternoon shift must not invent capacity.
     */
    public function test_somersby_day_and_afternoon_preserve_the_workbook_total(): void
    {
        $depot = Depot::query()->where('code', 'SOM')->sole();
        $resolver = app(OeeSettingResolver::class);

        $effectiveMinutes = Shift::query()
            ->where('depot_id', $depot->id)
            ->where('is_active', true)
            ->get()
            ->sum(function (Shift $shift) use ($depot, $resolver): int {
                $setting = $resolver->resolve($depot->id, $shift->id, null, CarbonImmutable::now());

                return (int) $shift->duration_minutes - $setting->nonProductiveMinutes();
            });

        $this->assertSame(885, $effectiveMinutes);
        $this->assertSame(14.75, $effectiveMinutes / 60);
    }

    #[DataProvider('oeeTargetsByPlant')]
    public function test_oee_targets_reconcile_with_the_workbooks(
        string $depotCode,
        string $performance,
        string $availability,
    ): void {
        $depot = Depot::query()->where('code', $depotCode)->sole();
        $shift = Shift::query()->where('depot_id', $depot->id)->where('code', 'DAY')->sole();
        $setting = app(OeeSettingResolver::class)
            ->resolve($depot->id, $shift->id, null, CarbonImmutable::now());

        $this->assertSame($performance, $setting->performance_percent);
        $this->assertSame($availability, $setting->availability_percent);
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function oeeTargetsByPlant(): array
    {
        return [
            'Melton' => ['MEL', '100.00', '60.00'],
            'Kilmore' => ['KIL', '80.00', '85.00'],
            'Somersby' => ['SOM', '87.00', '87.00'],
            'Thornton' => ['THO', '87.00', '87.00'],
            'Yatala' => ['YAT', '87.00', '87.00'],
        ];
    }

    public function test_afternoon_shift_runs_longer_midweek(): void
    {
        $depot = Depot::query()->where('code', 'MEL')->sole();
        $shift = Shift::query()
            ->with('patterns')
            ->where('depot_id', $depot->id)
            ->where('code', 'AFT')
            ->sole();

        // 10 hr Monday and Friday, 12 hr Tuesday to Thursday, no weekend.
        $this->assertSame(600, $shift->durationMinutesForWeekday(1));
        $this->assertSame(720, $shift->durationMinutesForWeekday(2));
        $this->assertSame(720, $shift->durationMinutesForWeekday(4));
        $this->assertSame(600, $shift->durationMinutesForWeekday(5));
        $this->assertNull($shift->durationMinutesForWeekday(6));
        $this->assertNull($shift->durationMinutesForWeekday(0));
    }

    public function test_capacity_for_a_seeded_plant_matches_the_workbook(): void
    {
        $depot = Depot::query()->where('code', 'MEL')->sole();
        $shift = Shift::query()->where('depot_id', $depot->id)->where('code', 'DAY')->sole();
        $setting = app(OeeSettingResolver::class)
            ->resolve($depot->id, $shift->id, null, CarbonImmutable::now());

        // VIC workbook 'Prod Plan' row 13: R0450-4 rated at 32/hr on Melton dayshift plans 173.
        $result = app(ShiftCapacityCalculator::class)->calculate(
            $shift,
            new SkuMaster(['standard_run_rate_per_hour' => '32.000']),
            oeeSetting: $setting,
        );

        $this->assertSame('9.000000', $result->productiveHours);
        $this->assertSame(173, $result->plannedPipes);
    }

    public function test_seeds_the_dumped_product_reason_and_cause_reference(): void
    {
        $this->assertSame(24, DumpReason::query()->where('product_type', DumpReason::TYPE_PIPE)->count());
        $this->assertSame(4, DumpReason::query()->where('product_type', DumpReason::TYPE_CAGE)->count());
        $this->assertSame(63, DumpCause::query()->count());

        $this->assertSame('Failed load test', DumpReason::query()->where('code', '122')->value('description'));
        $this->assertSame('Weak welds', DumpReason::query()->where('code', '201')->value('description'));
        $this->assertSame('machine', DumpCause::query()->where('description', 'BiDi set up/settings')->value('category'));
    }

    public function test_seeders_are_idempotent(): void
    {
        $this->seed([DepotSeeder::class, ShiftSeeder::class, OeeSettingSeeder::class]);

        $this->assertSame(5, Depot::query()->count());
        $this->assertSame(9, Shift::query()->where('is_active', true)->count());
    }
}
