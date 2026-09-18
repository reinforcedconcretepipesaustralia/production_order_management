<?php

namespace App\Services\Scheduling;

use App\Models\Shift;
use App\Models\SkuMaster;
use InvalidArgumentException;

final class ShiftCapacityCalculator
{
    public function calculate(Shift $shift, SkuMaster $sku): CapacityResult
    {
        $durationMinutes = $shift->duration_minutes;
        $prestartMinutes = $shift->prestart_minutes;
        $cleaningMinutes = $shift->cleaning_minutes;
        $performanceHundredths = $this->decimalToScaledInteger($shift->performance_target_percent, 2);
        $availabilityHundredths = $this->decimalToScaledInteger($shift->availability_target_percent, 2);
        $runRateThousandths = $this->decimalToScaledInteger($sku->standard_run_rate_per_hour, 3);

        if ($durationMinutes <= 0) {
            throw new InvalidArgumentException('Shift duration must be greater than zero.');
        }

        if ($prestartMinutes < 0 || $cleaningMinutes < 0 || $prestartMinutes + $cleaningMinutes >= $durationMinutes) {
            throw new InvalidArgumentException('Prestart and cleaning time must leave positive production time.');
        }

        if ($performanceHundredths < 0 || $performanceHundredths > 10_000) {
            throw new InvalidArgumentException('Performance percentage must be between 0 and 100.');
        }

        if ($availabilityHundredths < 0 || $availabilityHundredths > 10_000) {
            throw new InvalidArgumentException('Availability percentage must be between 0 and 100.');
        }

        if ($runRateThousandths <= 0) {
            throw new InvalidArgumentException('Standard run rate must be greater than zero.');
        }

        $productiveMinutes = $durationMinutes - $prestartMinutes - $cleaningMinutes;
        $effectiveNumerator = $productiveMinutes * $performanceHundredths * $availabilityHundredths;
        $effectiveDenominator = 60 * 10_000 * 10_000;
        $plannedPipes = intdiv($effectiveNumerator * $runRateThousandths, $effectiveDenominator * 1_000);

        return new CapacityResult(
            effectiveHours: $this->formatFraction($effectiveNumerator, $effectiveDenominator, 6),
            plannedPipes: $plannedPipes,
        );
    }

    private function decimalToScaledInteger(int|float|string $value, int $scale): int
    {
        $normalized = number_format((float) $value, $scale, '.', '');
        $negative = str_starts_with($normalized, '-');
        $digits = str_replace(['-', '.'], '', $normalized);

        return ($negative ? -1 : 1) * (int) $digits;
    }

    private function formatFraction(int $numerator, int $denominator, int $scale): string
    {
        $scaleFactor = 10 ** $scale;
        $scaled = intdiv(($numerator * $scaleFactor) + intdiv($denominator, 2), $denominator);

        return number_format($scaled / $scaleFactor, $scale, '.', '');
    }
}
