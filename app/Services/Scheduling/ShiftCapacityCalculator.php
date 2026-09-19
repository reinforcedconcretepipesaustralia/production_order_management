<?php

namespace App\Services\Scheduling;

use App\Models\OeeSetting;
use App\Models\Shift;
use App\Models\SkuMaster;
use InvalidArgumentException;

/**
 * Planned pipe capacity for one run, reproducing the formula behind 'Prod Plan' column O
 * and column S in each state's production planning workbook:
 *
 *     effective hours = shift length - prestart - breaks - cleaning - downtime
 *     planned pipes   = ROUNDUP(rated run rate * performance * availability
 *                               * effective hours * share of shift, 0)
 *
 * Arithmetic runs through bcmath rather than floats so the result is exact and matches the
 * workbook to the pipe. The scaled-integer approach this class used previously overflows
 * once the share-of-shift and run-rate scales are multiplied into the same product.
 */
final class ShiftCapacityCalculator
{
    /**
     * Denominator for the capacity expression: 60 minutes per hour, and 100 for each of
     * the two percentages.
     */
    private const CAPACITY_DIVISOR = '600000';

    private const SCALE = 12;

    /**
     * @param  OeeSetting|null  $oeeSetting  Resolved settings for this depot, shift and machine.
     *                                       Falls back to the shift's own targets and overheads.
     * @param  int|float|string|null  $runRatePerHour  Machine-specific rate; falls back to the
     *                                                 SKU's standard rate.
     * @param  int|null  $durationMinutes  Shift length for this weekday; falls back to the
     *                                     shift's default duration.
     * @param  int  $downtimeMinutes  Planned or unplanned downtime overlapping this shift.
     * @param  int|float|string  $shiftFraction  Share of the shift this run occupies, 0 to 1.
     */
    public function calculate(
        Shift $shift,
        SkuMaster $sku,
        ?OeeSetting $oeeSetting = null,
        int|float|string|null $runRatePerHour = null,
        ?int $durationMinutes = null,
        int $downtimeMinutes = 0,
        int|float|string $shiftFraction = 1,
    ): CapacityResult {
        $durationMinutes ??= (int) $shift->duration_minutes;
        $overheadMinutes = $oeeSetting?->nonProductiveMinutes()
            ?? (int) $shift->prestart_minutes + (int) $shift->cleaning_minutes;

        $performance = $this->normalizePercent(
            $oeeSetting?->performance_percent ?? $shift->performance_target_percent,
            'Performance',
        );
        $availability = $this->normalizePercent(
            $oeeSetting?->availability_percent ?? $shift->availability_target_percent,
            'Availability',
        );
        $runRate = $this->normalizeRunRate($runRatePerHour ?? $sku->standard_run_rate_per_hour);
        $fraction = $this->normalizeFraction($shiftFraction);

        if ($durationMinutes <= 0) {
            throw new InvalidArgumentException('Shift duration must be greater than zero.');
        }

        if ($overheadMinutes < 0 || $overheadMinutes >= $durationMinutes) {
            throw new InvalidArgumentException('Prestart and cleaning time must leave positive production time.');
        }

        if ($downtimeMinutes < 0) {
            throw new InvalidArgumentException('Downtime minutes cannot be negative.');
        }

        // Downtime consuming the whole shift is a legitimate plan — a full maintenance day —
        // unlike overheads exceeding the shift, which is a configuration error.
        $availableMinutes = max(0, $durationMinutes - $overheadMinutes - $downtimeMinutes);

        $effectiveHours = bcdiv(
            bcmul(bcmul((string) $availableMinutes, $performance, self::SCALE), $availability, self::SCALE),
            self::CAPACITY_DIVISOR,
            self::SCALE,
        );
        $plannedPipesExact = bcmul(bcmul($effectiveHours, $runRate, self::SCALE), $fraction, self::SCALE);

        return new CapacityResult(
            effectiveHours: $this->format($effectiveHours, 6),
            plannedPipes: $this->roundUp($plannedPipesExact),
            productiveHours: $this->format(bcdiv((string) $availableMinutes, '60', self::SCALE), 6),
            availableMinutes: $availableMinutes,
            downtimeMinutes: $downtimeMinutes,
            runRatePerHour: $this->format($runRate, 3),
            shiftFraction: $this->format($fraction, 4),
        );
    }

    private function normalizePercent(int|float|string $value, string $label): string
    {
        $normalized = number_format((float) $value, 4, '.', '');

        if (bccomp($normalized, '0', 4) < 0 || bccomp($normalized, '100', 4) > 0) {
            throw new InvalidArgumentException("{$label} percentage must be between 0 and 100.");
        }

        return $normalized;
    }

    private function normalizeRunRate(int|float|string $value): string
    {
        $normalized = number_format((float) $value, 3, '.', '');

        if (bccomp($normalized, '0', 3) <= 0) {
            throw new InvalidArgumentException('Standard run rate must be greater than zero.');
        }

        return $normalized;
    }

    /**
     * A share above one is an over-full shift. The workbooks do record these — Yatala plans
     * 1.11 on a day of overtime — so the model reproduces them rather than silently truncating
     * to a full shift and under-planning the day. Twice a shift is the ceiling, beyond which the
     * figure is almost certainly a mistake.
     */
    private function normalizeFraction(int|float|string $value): string
    {
        $normalized = number_format((float) $value, 4, '.', '');

        if (bccomp($normalized, '0', 4) <= 0 || bccomp($normalized, '2', 4) > 0) {
            throw new InvalidArgumentException('Share of shift must be greater than zero and no more than two.');
        }

        return $normalized;
    }

    /**
     * Excel's ROUNDUP(value, 0): any fractional part rounds away from zero.
     */
    private function roundUp(string $value): int
    {
        $truncated = bcadd($value, '0', 0);

        return bccomp($value, $truncated, self::SCALE) > 0
            ? (int) bcadd($truncated, '1', 0)
            : (int) $truncated;
    }

    /**
     * Round half-up to the given scale. bcadd truncates, so half a unit of the target
     * scale is added first — reported figures must not drift low.
     */
    private function format(string $value, int $scale): string
    {
        $half = '0.'.str_repeat('0', $scale).'5';

        return bccomp($value, '0', self::SCALE) < 0
            ? bcsub($value, $half, $scale)
            : bcadd($value, $half, $scale);
    }
}
