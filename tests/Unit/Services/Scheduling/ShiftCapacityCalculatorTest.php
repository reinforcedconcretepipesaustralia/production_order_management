<?php

namespace Tests\Unit\Services\Scheduling;

use App\Models\OeeSetting;
use App\Models\Shift;
use App\Models\SkuMaster;
use App\Services\Scheduling\ShiftCapacityCalculator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ShiftCapacityCalculatorTest extends TestCase
{
    /**
     * Reconciliation case from the VIC workbook: Melton dayshift running R0450-4.
     *
     * 'Prod Plan' row 13 reads AE13 = 32 (rated speed), O13 = AE13 * performance 1.00 *
     * availability 0.60 * effective hours 9 = 172.8, and S13 = ROUNDUP(P13 * O13, 0) = 173
     * at 100% of the shift. The 9 effective hours come from a 10 hr shift less 15 min
     * prestart and 45 min in-shift cleaning.
     */
    public function test_matches_the_vic_workbook_for_melton_dayshift(): void
    {
        $result = (new ShiftCapacityCalculator)->calculate(
            $this->meltonDayshift(),
            new SkuMaster(['standard_run_rate_per_hour' => '32.000']),
        );

        $this->assertSame('9.000000', $result->productiveHours);
        $this->assertSame('5.400000', $result->effectiveHours);
        $this->assertSame(173, $result->plannedPipes);
    }

    /**
     * The plants routinely run one product for part of a shift and another for the rest —
     * 'run the BiDi 36 for 50% of the day shift'. Half of 172.8 rounds up to 87.
     */
    public function test_applies_share_of_shift(): void
    {
        $result = (new ShiftCapacityCalculator)->calculate(
            $this->meltonDayshift(),
            new SkuMaster(['standard_run_rate_per_hour' => '32.000']),
            shiftFraction: 0.5,
        );

        $this->assertSame('0.5000', $result->shiftFraction);
        $this->assertSame(87, $result->plannedPipes);
    }

    public function test_rounds_planned_pipes_up_like_the_workbook(): void
    {
        $shift = new Shift([
            'duration_minutes' => 600,
            'prestart_minutes' => 15,
            'cleaning_minutes' => 30,
            'performance_target_percent' => '90.00',
            'availability_target_percent' => '95.00',
        ]);
        $sku = new SkuMaster(['standard_run_rate_per_hour' => '12.345']);

        $result = (new ShiftCapacityCalculator)->calculate($shift, $sku);

        // 9.25 productive hours * 0.90 * 0.95 = 7.90875, * 12.345 = 97.63351875.
        $this->assertSame('7.908750', $result->effectiveHours);
        $this->assertSame(98, $result->plannedPipes);
    }

    public function test_preserves_fractional_capacity_until_the_final_rounding(): void
    {
        $shift = new Shift([
            'duration_minutes' => 60,
            'prestart_minutes' => 0,
            'cleaning_minutes' => 0,
            'performance_target_percent' => '33.33',
            'availability_target_percent' => '33.33',
        ]);
        $sku = new SkuMaster(['standard_run_rate_per_hour' => '9.002']);

        $result = (new ShiftCapacityCalculator)->calculate($shift, $sku);

        // Barely over one pipe at 1.0000222, so it rounds up rather than truncating to 1.
        $this->assertSame('0.111089', $result->effectiveHours);
        $this->assertSame(2, $result->plannedPipes);
    }

    public function test_oee_settings_override_the_shift_targets_and_overheads(): void
    {
        // Kilmore dayshift: performance 0.80, availability 0.85, same 9 effective hours.
        $oee = new OeeSetting([
            'performance_percent' => '80.00',
            'availability_percent' => '85.00',
            'prestart_minutes' => 15,
            'paid_break_minutes' => 0,
            'unpaid_break_minutes' => 0,
            'in_shift_cleaning_minutes' => 45,
            'end_of_shift_cleaning_minutes' => 0,
        ]);

        $result = (new ShiftCapacityCalculator)->calculate(
            $this->meltonDayshift(),
            new SkuMaster(['standard_run_rate_per_hour' => '32.000']),
            oeeSetting: $oee,
        );

        // 9 hr * 0.80 * 0.85 = 6.12 hr, * 32 = 195.84.
        $this->assertSame('6.120000', $result->effectiveHours);
        $this->assertSame(196, $result->plannedPipes);
    }

    public function test_machine_run_rate_overrides_the_sku_standard_rate(): void
    {
        $result = (new ShiftCapacityCalculator)->calculate(
            $this->meltonDayshift(),
            new SkuMaster(['standard_run_rate_per_hour' => '32.000']),
            runRatePerHour: '20.000',
        );

        $this->assertSame('20.000', $result->runRatePerHour);
        $this->assertSame(108, $result->plannedPipes);
    }

    public function test_weekday_duration_overrides_the_default_shift_length(): void
    {
        // Melton afternoon runs 12 hr Tuesday to Thursday instead of the default 10 hr.
        $result = (new ShiftCapacityCalculator)->calculate(
            $this->meltonDayshift(),
            new SkuMaster(['standard_run_rate_per_hour' => '32.000']),
            durationMinutes: 720,
        );

        $this->assertSame(660, $result->availableMinutes);
        $this->assertSame('11.000000', $result->productiveHours);
        $this->assertSame(212, $result->plannedPipes);
    }

    public function test_downtime_reduces_available_capacity(): void
    {
        $result = (new ShiftCapacityCalculator)->calculate(
            $this->meltonDayshift(),
            new SkuMaster(['standard_run_rate_per_hour' => '32.000']),
            downtimeMinutes: 120,
        );

        $this->assertSame(420, $result->availableMinutes);
        $this->assertSame(120, $result->downtimeMinutes);
        // 7 hr * 0.60 = 4.2 effective hr, * 32 = 134.4.
        $this->assertSame(135, $result->plannedPipes);
    }

    public function test_downtime_consuming_the_whole_shift_yields_no_capacity(): void
    {
        $result = (new ShiftCapacityCalculator)->calculate(
            $this->meltonDayshift(),
            new SkuMaster(['standard_run_rate_per_hour' => '32.000']),
            downtimeMinutes: 600,
        );

        $this->assertSame(0, $result->availableMinutes);
        $this->assertSame(0, $result->plannedPipes);
    }

    public function test_rejects_overheads_that_consume_entire_shift(): void
    {
        $shift = new Shift([
            'duration_minutes' => 60,
            'prestart_minutes' => 30,
            'cleaning_minutes' => 30,
            'performance_target_percent' => 100,
            'availability_target_percent' => 100,
        ]);
        $sku = new SkuMaster(['standard_run_rate_per_hour' => 10]);

        $this->expectException(InvalidArgumentException::class);

        (new ShiftCapacityCalculator)->calculate($shift, $sku);
    }

    public function test_rejects_negative_downtime(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new ShiftCapacityCalculator)->calculate(
            $this->meltonDayshift(),
            new SkuMaster(['standard_run_rate_per_hour' => '32.000']),
            downtimeMinutes: -1,
        );
    }

    #[DataProvider('invalidShiftFractions')]
    public function test_rejects_invalid_share_of_shift(int|float|string $fraction): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new ShiftCapacityCalculator)->calculate(
            $this->meltonDayshift(),
            new SkuMaster(['standard_run_rate_per_hour' => '32.000']),
            shiftFraction: $fraction,
        );
    }

    /**
     * @return array<string, array{int|float|string}>
     */
    public static function invalidShiftFractions(): array
    {
        return [
            'zero' => [0],
            'negative' => [-0.5],
            'beyond twice a shift' => [2.5],
        ];
    }

    /**
     * The workbooks record over-full shifts — Yatala plans 1.11 on a day of overtime — so the
     * model reproduces them rather than truncating to a full shift and under-planning the day.
     */
    public function test_accepts_an_over_full_shift_as_overtime(): void
    {
        $result = (new ShiftCapacityCalculator)->calculate(
            $this->meltonDayshift(),
            new SkuMaster(['standard_run_rate_per_hour' => '32.000']),
            shiftFraction: 1.11,
        );

        $this->assertSame('1.1100', $result->shiftFraction);
        // 5.4 effective hr x 32/hr x 1.11 = 191.8, rounded up.
        $this->assertSame(192, $result->plannedPipes);
    }

    #[DataProvider('invalidPercentageAndRateValues')]
    public function test_rejects_invalid_percentages_and_run_rates(
        string $performance,
        string $availability,
        string $runRate,
    ): void {
        $shift = new Shift([
            'duration_minutes' => 60,
            'prestart_minutes' => 0,
            'cleaning_minutes' => 0,
            'performance_target_percent' => $performance,
            'availability_target_percent' => $availability,
        ]);
        $sku = new SkuMaster(['standard_run_rate_per_hour' => $runRate]);

        $this->expectException(InvalidArgumentException::class);

        (new ShiftCapacityCalculator)->calculate($shift, $sku);
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function invalidPercentageAndRateValues(): array
    {
        return [
            'negative performance' => ['-0.01', '100.00', '1.000'],
            'availability above one hundred' => ['100.00', '100.01', '1.000'],
            'zero run rate' => ['100.00', '100.00', '0.000'],
        ];
    }

    /**
     * Melton dayshift as configured on the VIC workbook's OEE Settings tab.
     */
    private function meltonDayshift(): Shift
    {
        return new Shift([
            'duration_minutes' => 600,
            'prestart_minutes' => 15,
            'cleaning_minutes' => 45,
            'performance_target_percent' => '100.00',
            'availability_target_percent' => '60.00',
        ]);
    }
}
