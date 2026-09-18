<?php

namespace Tests\Feature\Services\Scheduling;

use App\Exceptions\SchedulingConstraintException;
use App\Models\Depot;
use App\Models\MachineAsset;
use App\Models\MouldAsset;
use App\Models\ProductionSchedule;
use App\Models\Shift;
use App\Models\SkuMaster;
use App\Services\Scheduling\MouldConstraintService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class MouldConstraintServiceTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_detects_shared_component_and_machine_head_overlap_across_depots(): void
    {
        [$existing, $otherDepot, $otherMachine, $otherShift, $otherSku] = $this->createExistingSchedule();
        $candidateMould = MouldAsset::factory()->for($otherDepot, 'currentDepot')->create([
            'shared_component_constraint' => 'CORE-42',
            'machine_head_constraint' => 'HEAD-7',
        ]);
        $candidate = ProductionSchedule::factory()
            ->for($otherDepot)
            ->for($otherShift)
            ->for($otherMachine, 'machine')
            ->for($otherSku, 'sku')
            ->for($candidateMould, 'mould')
            ->make([
                'planned_start_at' => '2026-09-20 07:00:00',
                'planned_end_at' => '2026-09-20 09:00:00',
            ]);

        $conflicts = (new MouldConstraintService)->conflictsFor($candidate);

        $this->assertSame([$existing->id, $existing->id], $conflicts->pluck('conflictingScheduleId')->all());
        $this->assertStringContainsString('Shared component CORE-42', $conflicts[0]->reason);
        $this->assertStringContainsString('Machine head HEAD-7', $conflicts[1]->reason);
    }

    public function test_assertion_throws_for_same_mould_but_excludes_schedule_being_updated(): void
    {
        [$existing] = $this->createExistingSchedule();
        $service = new MouldConstraintService;

        $this->assertCount(0, $service->conflictsFor($existing));

        $candidate = $existing->replicate();
        $candidate->planned_start_at = '2026-09-20 07:00:00';
        $candidate->planned_end_at = '2026-09-20 08:00:00';

        $this->expectException(SchedulingConstraintException::class);
        $this->expectExceptionMessage('Mould');

        $service->assertNoConflicts($candidate);
    }

    public function test_reports_changeover_blocks_without_writing_a_schedule(): void
    {
        [$existing, $otherDepot, , $otherShift] = $this->createExistingSchedule();
        $candidateSku = SkuMaster::factory()->create(['diameter_mm' => 900]);
        $candidateMould = MouldAsset::factory()->for($otherDepot, 'currentDepot')->create([
            'mould_type' => 'rubber_ring_joint',
            'machine_head_constraint' => 'HEAD-99',
        ]);
        $candidate = ProductionSchedule::factory()
            ->for($otherDepot)
            ->for($otherShift)
            ->for($existing->machine, 'machine')
            ->for($candidateSku, 'sku')
            ->for($candidateMould, 'mould')
            ->make([
                'planned_start_at' => '2026-09-20 11:00:00',
                'planned_end_at' => '2026-09-20 13:00:00',
            ]);

        $assessment = (new MouldConstraintService)->requiredChangeoverBlocks($candidate);

        $this->assertSame($existing->id, $assessment->precedingScheduleId);
        $this->assertSame(
            ['diameter change', 'machine head change', 'mould configuration change'],
            $assessment->requiredBlocks,
        );
        $this->assertSame(1, ProductionSchedule::query()->count());
    }

    /**
     * @return array{ProductionSchedule, Depot, MachineAsset, Shift, SkuMaster}
     */
    private function createExistingSchedule(): array
    {
        $firstDepot = Depot::factory()->create(['code' => 'FIRST']);
        $otherDepot = Depot::factory()->create(['code' => 'OTHER']);
        $existingMachine = MachineAsset::factory()->for($firstDepot)->create();
        $otherMachine = MachineAsset::factory()->for($otherDepot)->create();
        $shift = Shift::factory()->for($otherDepot)->create();
        $sku = SkuMaster::factory()->create(['diameter_mm' => 600]);
        $mould = MouldAsset::factory()->for($firstDepot, 'currentDepot')->create([
            'mould_type' => 'flush_joint',
            'shared_component_constraint' => 'CORE-42',
            'machine_head_constraint' => 'HEAD-7',
        ]);
        $existing = ProductionSchedule::factory()
            ->for($firstDepot)
            ->for(Shift::factory()->for($firstDepot))
            ->for($existingMachine, 'machine')
            ->for($sku, 'sku')
            ->for($mould, 'mould')
            ->create([
                'planned_start_at' => '2026-09-20 06:00:00',
                'planned_end_at' => '2026-09-20 10:00:00',
            ]);

        return [$existing, $otherDepot, $otherMachine, $shift, $sku];
    }
}
