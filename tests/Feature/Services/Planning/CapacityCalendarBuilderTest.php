<?php

namespace Tests\Feature\Services\Planning;

use App\Models\Depot;
use App\Models\DepotShiftPattern;
use App\Models\DowntimeEvent;
use App\Models\MachineAsset;
use App\Models\NonProductionDay;
use App\Models\OeeSetting;
use App\Models\Shift;
use App\Services\Planning\CapacityCalendarBuilder;
use App\Services\Planning\CapacitySlot;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

class CapacityCalendarBuilderTest extends TestCase
{
    use RefreshDatabase;

    /** Monday 7 September 2026 through Sunday 13 September 2026. */
    private CarbonImmutable $monday;

    protected function setUp(): void
    {
        parent::setUp();

        $this->monday = CarbonImmutable::parse('2026-09-07');
    }

    public function test_builds_one_slot_per_machine_shift_and_weekday(): void
    {
        $depot = $this->depotWithDayShift();

        $slots = $this->build($depot);

        // Monday to Friday only: the seeded patterns do not cover the weekend.
        $this->assertCount(5, $slots);
        $this->assertSame(
            ['2026-09-07', '2026-09-08', '2026-09-09', '2026-09-10', '2026-09-11'],
            $slots->map(fn (CapacitySlot $slot): string => $slot->date->toDateString())->all(),
        );
    }

    public function test_uses_the_weekday_specific_shift_length(): void
    {
        $depot = $this->depotWithDayShift();
        $shift = $depot->shifts->first();

        // Melton's afternoon pattern: 10 hr Monday and Friday, 12 hr Tuesday to Thursday.
        DepotShiftPattern::query()->where('shift_id', $shift->id)->whereIn('weekday', [2, 3, 4])->update(['duration_minutes' => 720]);

        $byDate = $this->build($depot->fresh(['shifts.patterns', 'machines']))
            ->keyBy(fn (CapacitySlot $slot): string => $slot->date->toDateString());

        $this->assertSame(600, $byDate['2026-09-07']->durationMinutes);
        $this->assertSame(720, $byDate['2026-09-09']->durationMinutes);
        $this->assertSame(600, $byDate['2026-09-11']->durationMinutes);
    }

    public function test_skips_non_production_days(): void
    {
        $depot = $this->depotWithDayShift();
        NonProductionDay::factory()->create([
            'depot_id' => $depot->id,
            'date' => '2026-09-09',
            'reason' => 'Plant shutdown',
        ]);

        $dates = $this->build($depot)->map(fn (CapacitySlot $slot): string => $slot->date->toDateString())->all();

        $this->assertNotContains('2026-09-09', $dates);
        $this->assertCount(4, $dates);
    }

    public function test_omits_a_shift_that_does_not_run_on_a_weekday(): void
    {
        $depot = $this->depotWithDayShift();
        DepotShiftPattern::query()
            ->where('shift_id', $depot->shifts->first()->id)
            ->where('weekday', 3)
            ->update(['is_active' => false]);

        $dates = $this->build($depot->fresh(['shifts.patterns', 'machines']))
            ->map(fn (CapacitySlot $slot): string => $slot->date->toDateString())->all();

        $this->assertNotContains('2026-09-09', $dates);
    }

    public function test_subtracts_overlapping_downtime_from_productive_minutes(): void
    {
        $depot = $this->depotWithDayShift();
        $this->oeeFor($depot);

        DowntimeEvent::factory()->create([
            'depot_id' => $depot->id,
            'machine_asset_id' => null,
            'shift_id' => null,
            'starts_at' => '2026-09-09 08:00:00',
            'ends_at' => '2026-09-09 10:00:00',
            'category' => DowntimeEvent::CATEGORY_MAINTENANCE,
        ]);

        $byDate = $this->build($depot)->keyBy(fn (CapacitySlot $slot): string => $slot->date->toDateString());

        // 600 min shift less 60 min overheads is 540; two hours of maintenance leaves 420.
        $this->assertSame(0, $byDate['2026-09-08']->downtimeMinutes);
        $this->assertSame(540, $byDate['2026-09-08']->productiveMinutes());
        $this->assertSame(120, $byDate['2026-09-09']->downtimeMinutes);
        $this->assertSame(420, $byDate['2026-09-09']->productiveMinutes());
    }

    public function test_ignores_downtime_pinned_to_another_machine(): void
    {
        $depot = $this->depotWithDayShift();
        $other = MachineAsset::factory()->create(['depot_id' => $depot->id, 'code' => 'OTHER-1']);

        DowntimeEvent::factory()->create([
            'depot_id' => $depot->id,
            'machine_asset_id' => $other->id,
            'starts_at' => '2026-09-09 08:00:00',
            'ends_at' => '2026-09-09 10:00:00',
        ]);

        $slots = $this->build($depot->fresh(['shifts.patterns', 'machines']))
            ->filter(fn (CapacitySlot $slot): bool => $slot->date->toDateString() === '2026-09-09');

        $affected = $slots->firstWhere(fn (CapacitySlot $slot): bool => $slot->machine->is($other));
        $unaffected = $slots->first(fn (CapacitySlot $slot): bool => ! $slot->machine->is($other));

        $this->assertSame(120, $affected->downtimeMinutes);
        $this->assertSame(0, $unaffected->downtimeMinutes);
    }

    public function test_downtime_cannot_exceed_the_shift_length(): void
    {
        $depot = $this->depotWithDayShift();
        $this->oeeFor($depot);

        DowntimeEvent::factory()->create([
            'depot_id' => $depot->id,
            'starts_at' => '2026-09-08 00:00:00',
            'ends_at' => '2026-09-10 00:00:00',
            'category' => DowntimeEvent::CATEGORY_SHUTDOWN,
        ]);

        $byDate = $this->build($depot)->keyBy(fn (CapacitySlot $slot): string => $slot->date->toDateString());

        $this->assertSame(600, $byDate['2026-09-09']->downtimeMinutes);
        $this->assertSame(0, $byDate['2026-09-09']->productiveMinutes());
        $this->assertFalse($byDate['2026-09-09']->hasCapacity());
    }

    public function test_resolves_the_oee_settings_in_force(): void
    {
        $depot = $this->depotWithDayShift();
        $this->oeeFor($depot);

        $slot = $this->build($depot)->first();

        $this->assertNotNull($slot->oeeSetting);
        $this->assertSame('100.00', $slot->oeeSetting->performance_percent);
        $this->assertSame('60.00', $slot->oeeSetting->availability_percent);
        $this->assertSame(60, $slot->oeeSetting->nonProductiveMinutes());
    }

    public function test_slots_track_remaining_share_of_shift(): void
    {
        $slot = $this->build($this->depotWithDayShift())->first();

        $this->assertSame('1.0000', $slot->remainingFraction());
        $this->assertSame('0.5000', $slot->claim('0.5000'));
        $this->assertSame('0.5000', $slot->remainingFraction());

        // A claim larger than what is left is capped rather than overbooking the shift.
        $this->assertSame('0.5000', $slot->claim('0.8000'));
        $this->assertSame('0.0000', $slot->remainingFraction());
        $this->assertFalse($slot->hasCapacity());
    }

    public function test_returns_nothing_when_the_horizon_is_inverted(): void
    {
        $depot = $this->depotWithDayShift();

        $slots = app(CapacityCalendarBuilder::class)->build(
            collect([$depot]),
            $this->monday->addWeek(),
            $this->monday,
        );

        $this->assertTrue($slots->isEmpty());
    }

    /**
     * @return Collection<int, CapacitySlot>
     */
    private function build(Depot $depot): Collection
    {
        return app(CapacityCalendarBuilder::class)->build(
            collect([$depot]),
            $this->monday,
            $this->monday->addDays(6),
        );
    }

    private function depotWithDayShift(): Depot
    {
        $depot = Depot::factory()->create(['code' => 'MEL', 'name' => 'Melton', 'state' => 'VIC']);
        $shift = Shift::factory()->create([
            'depot_id' => $depot->id,
            'code' => 'DAY',
            'starts_at' => '06:00:00',
            'duration_minutes' => 600,
            'prestart_minutes' => 15,
            'cleaning_minutes' => 45,
        ]);

        foreach ([1, 2, 3, 4, 5] as $weekday) {
            DepotShiftPattern::factory()->create([
                'depot_id' => $depot->id,
                'shift_id' => $shift->id,
                'weekday' => $weekday,
                'duration_minutes' => 600,
            ]);
        }

        MachineAsset::factory()->create(['depot_id' => $depot->id, 'code' => 'BIDI-36']);

        return $depot->fresh(['shifts.patterns', 'machines']);
    }

    private function oeeFor(Depot $depot): OeeSetting
    {
        return OeeSetting::factory()->create([
            'depot_id' => $depot->id,
            'shift_id' => $depot->shifts->first()->id,
            'effective_from' => '2026-09-01',
            'performance_percent' => 100,
            'availability_percent' => 60,
            'prestart_minutes' => 15,
            'in_shift_cleaning_minutes' => 45,
        ]);
    }
}
