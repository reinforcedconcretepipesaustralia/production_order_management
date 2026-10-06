<?php

namespace App\Services\Scheduling;

use App\Enums\ProductionQualityStatus;
use App\Exceptions\InvalidProductionActualException;
use App\Models\ProductionActual;
use App\Models\ProductionSchedule;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * What the floor actually made on a planned batch, before anyone keys it into Tencia.
 *
 * One current count per line: the first save creates it, later saves update it. Quality release
 * stays a separate step — this service will not set QC sign-off.
 */
final class RecordProductionActualService
{
    /**
     * Fields a team lead may type after a run.
     *
     * @var list<string>
     */
    private const EDITABLE = [
        'manufactured_at',
        'good_quantity',
        'dumped_quantity',
        'dump_reason',
        'notes',
    ];

    /**
     * @param  array<string, mixed>  $changes
     */
    public function record(ProductionSchedule $line, array $changes): ProductionActual
    {
        $this->guardEditable($changes);

        $actual = $line->latestActual;

        if ($actual !== null && $actual->quality_status->isDispatchEligible()) {
            throw new RuntimeException('This batch has been QC released. Ask quality if the count needs to change.');
        }

        try {
            if ($actual === null) {
                $actual = $line->actuals()->make([
                    'manufactured_at' => $this->defaultManufacturedAt($line),
                    'good_quantity' => 0,
                    'dumped_quantity' => 0,
                    'quality_status' => ProductionQualityStatus::Curing,
                ]);
            }

            foreach ($changes as $field => $value) {
                $actual->setAttribute($field, $this->normalise($field, $value, $actual, $line));
            }

            $actual->save();
        } catch (InvalidProductionActualException $exception) {
            throw new RuntimeException($exception->getMessage(), previous: $exception);
        }

        return $actual;
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function guardEditable(array $changes): void
    {
        foreach (array_keys($changes) as $field) {
            if (! in_array($field, self::EDITABLE, true)) {
                throw new RuntimeException("[{$field}] is not a field the floor may change.");
            }
        }
    }

    private function defaultManufacturedAt(ProductionSchedule $line): CarbonImmutable
    {
        if ($line->planned_end_at !== null) {
            return CarbonImmutable::parse($line->planned_end_at);
        }

        if ($line->schedule_date !== null) {
            return CarbonImmutable::parse($line->schedule_date->toDateString())->setTime(12, 0);
        }

        return CarbonImmutable::now();
    }

    private function normalise(
        string $field,
        mixed $value,
        ProductionActual $actual,
        ProductionSchedule $line,
    ): mixed {
        return match ($field) {
            'good_quantity', 'dumped_quantity' => max(0, (int) $value),
            'dump_reason', 'notes' => $this->nullableString($value),
            'manufactured_at' => $this->normaliseManufacturedAt($value, $actual, $line),
            default => $value,
        };
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    private function normaliseManufacturedAt(
        mixed $value,
        ProductionActual $actual,
        ProductionSchedule $line,
    ): CarbonImmutable {
        if ($value === null || $value === '') {
            throw new RuntimeException('Manufacture time is required.');
        }

        $parsed = CarbonImmutable::parse((string) $value);

        if ($this->isDateOnly((string) $value)) {
            $time = $actual->manufactured_at ?? $this->defaultManufacturedAt($line);

            return $parsed->setTime((int) $time->format('H'), (int) $time->format('i'), (int) $time->format('s'));
        }

        return $parsed;
    }

    private function isDateOnly(string $value): bool
    {
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $value);
    }
}
