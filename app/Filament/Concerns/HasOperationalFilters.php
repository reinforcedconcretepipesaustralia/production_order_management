<?php

namespace App\Filament\Concerns;

use App\Models\Depot;
use App\Models\MachineAsset;
use App\Models\Shift;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Shared Plant / Shift / Machines filters for Operations screens.
 *
 * Shift and machine dropdowns list distinct names (plants share labels like
 * "Day Shift" and "BiDi 36"). Selecting a name matches every record with that
 * label in the current plant scope.
 */
trait HasOperationalFilters
{
    use HasOperationalDepotScope;

    public ?int $depotId = null;

    public string $shiftName = '';

    public string $machineName = '';

    public function updatedDepotId(mixed $value): void
    {
        $this->depotId = $this->nullablePositiveInt($value);
        $this->syncOperationalFilterSelection();
    }

    public function updatedShiftName(mixed $value): void
    {
        $this->shiftName = $this->nullableFilterLabel($value);
        $this->syncOperationalFilterSelection();
    }

    public function updatedMachineName(mixed $value): void
    {
        $this->machineName = $this->nullableFilterLabel($value);
        $this->syncOperationalFilterSelection();
    }

    /**
     * @return array{
     *     plants: Collection<int, Depot>,
     *     shifts: Collection<int, string>,
     *     machines: Collection<int, string>
     * }
     */
    protected function operationalFilterOptions(): array
    {
        $this->syncOperationalFilterSelection();

        $plants = $this->accessiblePlants();
        $plantIds = $plants->modelKeys();
        $selectedPlantId = $this->depotId;

        $shifts = Shift::query()
            ->where('is_active', true)
            ->whereIn('depot_id', $plantIds)
            ->when($selectedPlantId !== null, fn (Builder $query) => $query->where('depot_id', $selectedPlantId))
            ->whereNotNull('name')
            ->where('name', '!=', '')
            ->orderBy('name')
            ->distinct()
            ->pluck('name')
            ->values();

        $machines = MachineAsset::query()
            ->where('is_active', true)
            ->whereIn('depot_id', $plantIds)
            ->when($selectedPlantId !== null, fn (Builder $query) => $query->where('depot_id', $selectedPlantId))
            ->orderBy('name')
            ->orderBy('code')
            ->get(['name', 'code'])
            ->map(fn (MachineAsset $machine): string => $machine->name ?: $machine->code)
            ->filter()
            ->unique()
            ->sort()
            ->values();

        return [
            'plants' => $plants,
            'shifts' => $shifts,
            'machines' => $machines,
        ];
    }

    /**
     * @return Collection<int, Depot>
     */
    protected function accessiblePlants(): Collection
    {
        $accessibleDepotIds = $this->accessibleDepotIds();

        return Depot::query()
            ->where('is_active', true)
            ->when($accessibleDepotIds !== null, fn (Builder $query) => $query->whereKey($accessibleDepotIds))
            ->orderBy('name')
            ->get();
    }

    /**
     * Drop selections the current user cannot see, or that no longer match the plant.
     */
    protected function syncOperationalFilterSelection(): void
    {
        $plants = $this->accessiblePlants();
        $plantIds = $plants->modelKeys();

        if ($this->depotId !== null && ! $plants->contains('id', $this->depotId)) {
            $this->depotId = null;
        }

        $selectedPlantId = $this->depotId;
        $this->shiftName = $this->nullableFilterLabel($this->shiftName);
        $this->machineName = $this->nullableFilterLabel($this->machineName);

        if ($this->shiftName !== '') {
            $shiftExists = Shift::query()
                ->where('is_active', true)
                ->whereIn('depot_id', $plantIds)
                ->when($selectedPlantId !== null, fn (Builder $query) => $query->where('depot_id', $selectedPlantId))
                ->where('name', $this->shiftName)
                ->exists();

            if (! $shiftExists) {
                $this->shiftName = '';
            }
        }

        if ($this->machineName !== '') {
            $machineExists = MachineAsset::query()
                ->where('is_active', true)
                ->whereIn('depot_id', $plantIds)
                ->when($selectedPlantId !== null, fn (Builder $query) => $query->where('depot_id', $selectedPlantId))
                ->where(function (Builder $query): void {
                    $query->where('name', $this->machineName)
                        ->orWhere(function (Builder $fallback): void {
                            $fallback->where(function (Builder $emptyName): void {
                                $emptyName->whereNull('name')->orWhere('name', '');
                            })->where('code', $this->machineName);
                        });
                })
                ->exists();

            if (! $machineExists) {
                $this->machineName = '';
            }
        }
    }

    /**
     * @param  Builder<*>  $query
     * @return Builder<*>
     */
    protected function applyOperationalFilters(
        Builder $query,
        string $depotColumn = 'depot_id',
        string $shiftColumn = 'shift_id',
        string $machineColumn = 'machine_asset_id',
    ): Builder {
        $this->syncOperationalFilterSelection();

        $accessibleDepotIds = $this->accessibleDepotIds();
        $shiftIds = $this->matchingShiftIds();
        $machineIds = $this->matchingMachineIds();

        return $query
            ->when(
                $accessibleDepotIds !== null,
                fn (Builder $builder) => $builder->whereIn($depotColumn, $accessibleDepotIds),
            )
            ->when($this->depotId !== null, fn (Builder $builder) => $builder->where($depotColumn, $this->depotId))
            ->when($this->shiftName !== '', fn (Builder $builder) => $builder->whereIn($shiftColumn, $shiftIds))
            ->when($this->machineName !== '', fn (Builder $builder) => $builder->whereIn($machineColumn, $machineIds));
    }

    /**
     * Plants implied by the current filter selection. A shift or machine without an
     * explicit plant still narrows the scope when that label exists at only one plant.
     *
     * @return list<int>|null Null means every accessible plant.
     */
    protected function selectedPlantIds(): ?array
    {
        $this->syncOperationalFilterSelection();

        if ($this->depotId !== null) {
            return [$this->depotId];
        }

        $plantIds = null;

        if ($this->shiftName !== '') {
            $shiftIds = $this->matchingShiftIds();
            $plantIds = $shiftIds === []
                ? []
                : Shift::query()->whereKey($shiftIds)->pluck('depot_id')->unique()->map(fn (mixed $id): int => (int) $id)->values()->all();
        }

        if ($this->machineName !== '') {
            $machineIds = $this->matchingMachineIds();
            $machinePlantIds = $machineIds === []
                ? []
                : MachineAsset::query()->whereKey($machineIds)->pluck('depot_id')->unique()->map(fn (mixed $id): int => (int) $id)->values()->all();

            $plantIds = $plantIds === null
                ? $machinePlantIds
                : array_values(array_intersect($plantIds, $machinePlantIds));
        }

        return $plantIds;
    }

    /**
     * @return list<int>
     */
    protected function matchingShiftIds(): array
    {
        if ($this->shiftName === '') {
            return [];
        }

        $plants = $this->accessiblePlants();

        return Shift::query()
            ->where('is_active', true)
            ->whereIn('depot_id', $plants->modelKeys())
            ->when($this->depotId !== null, fn (Builder $query) => $query->where('depot_id', $this->depotId))
            ->where('name', $this->shiftName)
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * @return list<int>
     */
    protected function matchingMachineIds(): array
    {
        if ($this->machineName === '') {
            return [];
        }

        $plants = $this->accessiblePlants();

        return MachineAsset::query()
            ->where('is_active', true)
            ->whereIn('depot_id', $plants->modelKeys())
            ->when($this->depotId !== null, fn (Builder $query) => $query->where('depot_id', $this->depotId))
            ->where(function (Builder $query): void {
                $query->where('name', $this->machineName)
                    ->orWhere(function (Builder $fallback): void {
                        $fallback->where(function (Builder $emptyName): void {
                            $emptyName->whereNull('name')->orWhere('name', '');
                        })->where('code', $this->machineName);
                    });
            })
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    private function nullablePositiveInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $int = (int) $value;

        return $int > 0 ? $int : null;
    }

    private function nullableFilterLabel(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        return trim((string) $value);
    }
}
