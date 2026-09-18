<?php

namespace Tests\Feature\Services\Scheduling;

use App\Enums\ProductionQualityStatus;
use App\Exceptions\InvalidProductionActualException;
use App\Models\ProductionActual;
use App\Models\ProductionSchedule;
use App\Services\Scheduling\QualityCuringService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class QualityCuringServiceTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_remains_curing_before_seven_calendar_days(): void
    {
        $actual = $this->actualForManufactureDate('2026-09-01 12:00:00', true);

        $result = (new QualityCuringService)->evaluate($actual, '2026-09-08 11:59:59');

        $this->assertSame('2026-09-08', $result->releaseDate->toDateString());
        $this->assertSame('Curing', $result->status);
        $this->assertFalse($result->isEligible);
    }

    public function test_becomes_eligible_exactly_at_seven_days_when_qc_released(): void
    {
        $actual = $this->actualForManufactureDate('2026-09-01 12:00:00', true);

        $result = (new QualityCuringService)->evaluate($actual, '2026-09-08 12:00:00');

        $this->assertSame('Released', $result->status);
        $this->assertTrue($result->isEligible);
    }

    public function test_remains_on_quality_hold_after_curing_without_qc_release(): void
    {
        $actual = $this->actualForManufactureDate('2026-09-01 12:00:00', false);

        $result = (new QualityCuringService)->evaluate($actual, '2026-09-08 12:00:00');

        $this->assertSame('Quality Hold', $result->status);
        $this->assertFalse($result->isEligible);
    }

    public function test_rejects_dumped_quantity_without_a_reason_on_save(): void
    {
        $schedule = ProductionSchedule::factory()->create();

        $this->expectException(InvalidProductionActualException::class);
        $this->expectExceptionMessage('dump reason');

        ProductionActual::factory()->for($schedule, 'schedule')->create([
            'dumped_quantity' => 1,
            'dump_reason' => ' ',
        ]);
    }

    public function test_rejects_released_status_without_qc_sign_off(): void
    {
        $schedule = ProductionSchedule::factory()->create();

        $this->expectException(InvalidProductionActualException::class);
        $this->expectExceptionMessage('sign-off');

        ProductionActual::factory()->for($schedule, 'schedule')->create([
            'manufactured_at' => '2026-09-01 00:00:00',
            'quality_status' => ProductionQualityStatus::Released,
            'qc_released_at' => null,
            'qc_released_by' => null,
        ]);
    }

    private function actualForManufactureDate(string $manufacturedAt, bool $released): ProductionActual
    {
        $schedule = ProductionSchedule::factory()->create(['schedule_date' => '2026-09-01']);

        return ProductionActual::factory()->for($schedule, 'schedule')->create([
            'manufactured_at' => $manufacturedAt,
            'quality_status' => $released
                ? ProductionQualityStatus::Released
                : ProductionQualityStatus::QualityHold,
            'qc_released_at' => $released ? '2026-09-08 12:00:00' : null,
            'qc_released_by' => $released ? 'quality.manager@rcpa.com.au' : null,
        ]);
    }
}
