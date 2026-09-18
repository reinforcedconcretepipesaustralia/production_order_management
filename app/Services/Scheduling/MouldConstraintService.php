<?php

namespace App\Services\Scheduling;

use App\Exceptions\SchedulingConstraintException;
use App\Models\ProductionSchedule;
use Illuminate\Support\Collection;

final class MouldConstraintService
{
    /**
     * @return Collection<int, MouldConflict>
     */
    public function conflictsFor(ProductionSchedule $candidate): Collection
    {
        $candidate->loadMissing(['mould', 'sku']);

        if ($candidate->planned_start_at === null || $candidate->planned_end_at === null) {
            throw new \InvalidArgumentException('A schedule must have a planned start and end time.');
        }

        if ($candidate->planned_end_at <= $candidate->planned_start_at) {
            throw new \InvalidArgumentException('Planned end time must be after planned start time.');
        }

        $overlappingSchedules = ProductionSchedule::query()
            ->with(['mould', 'machine', 'depot'])
            ->when($candidate->exists, fn ($query) => $query->whereKeyNot($candidate->getKey()))
            ->where('planned_start_at', '<', $candidate->planned_end_at)
            ->where('planned_end_at', '>', $candidate->planned_start_at)
            ->get();

        return $overlappingSchedules->flatMap(function (ProductionSchedule $existing) use ($candidate): array {
            $reasons = [];

            if ($candidate->mould_asset_id !== null && $candidate->mould_asset_id === $existing->mould_asset_id) {
                $reasons[] = sprintf(
                    'Mould %s is already used by schedule %d at %s.',
                    $candidate->mould?->code ?? (string) $candidate->mould_asset_id,
                    $existing->id,
                    $existing->depot?->code ?? 'another depot',
                );
            }

            $sharedComponent = $candidate->mould?->shared_component_constraint;
            if ($sharedComponent !== null && $sharedComponent !== '' && $sharedComponent === $existing->mould?->shared_component_constraint) {
                $reasons[] = sprintf(
                    'Shared component %s is already used by schedule %d at %s.',
                    $sharedComponent,
                    $existing->id,
                    $existing->depot?->code ?? 'another depot',
                );
            }

            $machineHead = $candidate->mould?->machine_head_constraint;
            if ($machineHead !== null && $machineHead !== '' && $machineHead === $existing->mould?->machine_head_constraint) {
                $reasons[] = sprintf(
                    'Machine head %s is already used by schedule %d on machine %s.',
                    $machineHead,
                    $existing->id,
                    $existing->machine?->code ?? (string) $existing->machine_asset_id,
                );
            }

            return array_map(
                fn (string $reason): MouldConflict => new MouldConflict($existing->id, $reason),
                $reasons,
            );
        })->values();
    }

    public function assertNoConflicts(ProductionSchedule $candidate): void
    {
        $conflicts = $this->conflictsFor($candidate);

        if ($conflicts->isNotEmpty()) {
            throw new SchedulingConstraintException($conflicts->pluck('reason')->all());
        }
    }

    public function requiredChangeoverBlocks(ProductionSchedule $candidate): ChangeoverAssessment
    {
        $candidate->loadMissing(['sku', 'mould']);

        $preceding = ProductionSchedule::query()
            ->with(['sku', 'mould'])
            ->where('machine_asset_id', $candidate->machine_asset_id)
            ->when($candidate->exists, fn ($query) => $query->whereKeyNot($candidate->getKey()))
            ->where('planned_end_at', '<=', $candidate->planned_start_at)
            ->orderByDesc('planned_end_at')
            ->orderByDesc('id')
            ->first();

        if ($preceding === null) {
            return new ChangeoverAssessment(null, []);
        }

        $blocks = [];

        if ($preceding->sku?->diameter_mm !== $candidate->sku?->diameter_mm) {
            $blocks[] = 'diameter change';
        }

        if ($preceding->mould?->machine_head_constraint !== $candidate->mould?->machine_head_constraint) {
            $blocks[] = 'machine head change';
        }

        if (
            $preceding->mould_asset_id !== $candidate->mould_asset_id
            || $preceding->mould?->mould_type !== $candidate->mould?->mould_type
        ) {
            $blocks[] = 'mould configuration change';
        }

        return new ChangeoverAssessment($preceding->id, $blocks);
    }
}
