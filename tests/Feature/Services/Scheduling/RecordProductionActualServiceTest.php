<?php

namespace Tests\Feature\Services\Scheduling;

use App\Enums\ProductionQualityStatus;
use App\Models\ProductionActual;
use App\Models\ProductionSchedule;
use App\Services\Scheduling\RecordProductionActualService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class RecordProductionActualServiceTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_creates_the_first_actual_for_a_batch_and_defaults_the_manufacture_time(): void
    {
        $line = ProductionSchedule::factory()->create([
            'schedule_date' => '2026-09-28',
            'planned_start_at' => '2026-09-28 06:00:00',
            'planned_end_at' => '2026-09-28 14:00:00',
            'planned_quantity' => 80,
        ]);

        $actual = $this->service()->record($line, ['good_quantity' => 76]);

        $this->assertSame(76, $actual->good_quantity);
        $this->assertSame(0, $actual->dumped_quantity);
        $this->assertSame(ProductionQualityStatus::Curing, $actual->quality_status);
        $this->assertNull($actual->qc_released_at);
        $this->assertSame('2026-09-28 14:00:00', $actual->manufactured_at->format('Y-m-d H:i:s'));
        $this->assertSame(1, $line->actuals()->count());
    }

    public function test_updates_the_existing_count_instead_of_adding_another_row(): void
    {
        $line = ProductionSchedule::factory()->create([
            'planned_end_at' => '2026-09-28 14:00:00',
        ]);
        $this->service()->record($line, ['good_quantity' => 70]);

        $updated = $this->service()->record($line->fresh(), [
            'good_quantity' => 72,
            'dumped_quantity' => 2,
            'dump_reason' => 'Damaged socket',
        ]);

        $this->assertSame(72, $updated->good_quantity);
        $this->assertSame(2, $updated->dumped_quantity);
        $this->assertSame('Damaged socket', $updated->dump_reason);
        $this->assertSame(1, $line->actuals()->count());
    }

    public function test_rejects_dumped_quantity_without_a_reason(): void
    {
        $line = ProductionSchedule::factory()->create();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('dump reason');

        $this->service()->record($line, ['dumped_quantity' => 1]);
    }

    public function test_rejects_a_change_after_quality_has_released_the_batch(): void
    {
        $line = ProductionSchedule::factory()->create(['schedule_date' => '2026-09-01']);
        ProductionActual::factory()->for($line, 'schedule')->create([
            'manufactured_at' => '2026-09-01 12:00:00',
            'good_quantity' => 40,
            'quality_status' => ProductionQualityStatus::Released,
            'qc_released_at' => '2026-09-08 12:00:00',
            'qc_released_by' => 'quality.manager@rcpa.com.au',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('QC released');

        $this->service()->record($line->fresh(['latestActual']), ['good_quantity' => 41]);
    }

    public function test_rejects_quality_fields_the_floor_must_not_set(): void
    {
        $line = ProductionSchedule::factory()->create();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('[quality_status]');

        $this->service()->record($line, ['quality_status' => 'released']);
    }

    private function service(): RecordProductionActualService
    {
        return new RecordProductionActualService;
    }
}
