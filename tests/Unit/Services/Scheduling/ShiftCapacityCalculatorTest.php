<?php

namespace Tests\Unit\Services\Scheduling;

use App\Models\Shift;
use App\Models\SkuMaster;
use App\Services\Scheduling\ShiftCapacityCalculator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ShiftCapacityCalculatorTest extends TestCase
{
    public function test_returns_effective_hours_and_floored_pipe_capacity(): void
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

        $this->assertSame('7.908750', $result->effectiveHours);
        $this->assertSame(97, $result->plannedPipes);
    }

    public function test_preserves_fractional_capacity_until_final_floor(): void
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

        $this->assertSame('0.111089', $result->effectiveHours);
        $this->assertSame(1, $result->plannedPipes);
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
}
