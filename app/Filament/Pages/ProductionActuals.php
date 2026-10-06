<?php

namespace App\Filament\Pages;

use App\Enums\Capability;
use App\Enums\ProductionQualityStatus;
use App\Filament\Concerns\HasOperationalFilters;
use App\Models\ProductionSchedule;
use App\Services\Scheduling\RecordProductionActualService;
use Carbon\CarbonImmutable;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

/**
 * After a run, the production manager or team lead types what was actually made so the same
 * numbers can be keyed into Tencia. Quality release is a later step and is not on this screen.
 */
class ProductionActuals extends Page implements Tables\Contracts\HasTable
{
    use HasOperationalFilters;
    use Tables\Concerns\InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static string|\UnitEnum|null $navigationGroup = 'Operations';

    protected static ?string $navigationLabel = 'Production Actuals';

    protected static ?string $title = 'Production Actuals';

    protected static ?int $navigationSort = 8;

    protected string $view = 'filament.pages.production-actuals';

    public string $fromDate = '';

    public string $toDate = '';

    public function mount(): void
    {
        $this->fromDate = now()->subDays(7)->toDateString();
        $this->toDate = now()->toDateString();
    }

    public static function canAccess(): bool
    {
        return static::canViewOperationalPage();
    }

    public function canEnter(): bool
    {
        $user = static::operationalUser();

        if ($user === null) {
            return false;
        }

        if ($user->hasRole(config('filament-shield.super_admin.name'))) {
            return true;
        }

        return $user->hasCapability(Capability::EnterProductionActuals);
    }

    public function table(Table $table): Table
    {
        $editable = $this->canEnter();

        return $table
            ->query($this->linesQuery())
            ->defaultGroup('depot.name')
            ->defaultSort('schedule_date')
            ->paginated([25, 50, 100])
            ->columns([
                TextColumn::make('sku.sku_code')
                    ->label('Product')
                    ->searchable()
                    ->sortable()
                    ->description(fn (ProductionSchedule $line): string => $line->depot?->code ?? ''),
                TextColumn::make('schedule_date')
                    ->label('Planned')
                    ->date('j M')
                    ->sortable(),
                TextColumn::make('planned_quantity')
                    ->label('Planned pipes')
                    ->alignEnd()
                    ->numeric(),
                TextColumn::make('shift.name')
                    ->label('Shift')
                    ->placeholder('—'),
                TextColumn::make('machine.name')
                    ->label('Machine')
                    ->placeholder('—')
                    ->searchable(),
                TextInputColumn::make('manufactured_at')
                    ->label('Made on')
                    ->type('date')
                    ->disabled(fn (): bool => ! $editable)
                    ->getStateUsing(fn (ProductionSchedule $line): ?string => $this->displayState($line, 'manufactured_at'))
                    ->updateStateUsing(fn (ProductionSchedule $record, mixed $state): mixed => $this->persistLine(
                        $record,
                        ['manufactured_at' => $state],
                    )),
                TextInputColumn::make('good_quantity')
                    ->label('Good pipes')
                    ->type('number')
                    ->inputMode('numeric')
                    ->step('1')
                    ->alignEnd()
                    ->disabled(fn (): bool => ! $editable)
                    ->getStateUsing(fn (ProductionSchedule $line): ?int => $this->displayState($line, 'good_quantity'))
                    ->updateStateUsing(fn (ProductionSchedule $record, mixed $state): mixed => $this->persistLine(
                        $record,
                        ['good_quantity' => $state],
                    )),
                TextInputColumn::make('dumped_quantity')
                    ->label('Dumped')
                    ->type('number')
                    ->inputMode('numeric')
                    ->step('1')
                    ->alignEnd()
                    ->disabled(fn (): bool => ! $editable)
                    ->getStateUsing(fn (ProductionSchedule $line): ?int => $this->displayState($line, 'dumped_quantity'))
                    ->updateStateUsing(fn (ProductionSchedule $record, mixed $state): mixed => $this->persistLine(
                        $record,
                        ['dumped_quantity' => $state],
                    )),
                TextInputColumn::make('dump_reason')
                    ->label('Dump reason')
                    ->disabled(fn (): bool => ! $editable)
                    ->getStateUsing(fn (ProductionSchedule $line): ?string => $this->displayState($line, 'dump_reason'))
                    ->updateStateUsing(fn (ProductionSchedule $record, mixed $state): mixed => $this->persistLine(
                        $record,
                        ['dump_reason' => $state],
                    )),
                TextColumn::make('latestActual.quality_status')
                    ->label('Status')
                    ->placeholder('Not entered')
                    ->formatStateUsing(function (mixed $state): string {
                        $value = $state instanceof ProductionQualityStatus ? $state->value : $state;

                        return $value === null || $value === ''
                            ? 'Not entered'
                            : str((string) $value)->replace('_', ' ')->ucfirst()->toString();
                    }),
            ])
            ->emptyStateHeading('No batches in this window')
            ->emptyStateDescription('Widen the dates or plant filter, or wait until a plan line falls in the window.');
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return [
            ...$this->operationalFilterOptions(),
            'canEnter' => $this->canEnter(),
        ];
    }

    /**
     * @return Builder<ProductionSchedule>
     */
    private function linesQuery(): Builder
    {
        [$from, $to] = $this->dateRange();

        return $this->applyOperationalFilters(
            ProductionSchedule::query()
                ->with(['depot', 'machine', 'shift', 'sku', 'latestActual'])
                ->whereNot('status', 'cancelled')
                ->whereDate('schedule_date', '>=', $from->toDateString())
                ->whereDate('schedule_date', '<=', $to->toDateString())
                ->orderBy('depot_id')
                ->orderBy('schedule_date')
                ->orderBy('planned_start_at')
                ->orderBy('id'),
        );
    }

    /**
     * @return array{CarbonImmutable, CarbonImmutable}
     */
    private function dateRange(): array
    {
        try {
            $from = CarbonImmutable::parse($this->fromDate)->startOfDay();
            $to = CarbonImmutable::parse($this->toDate)->startOfDay();
        } catch (\Throwable) {
            $from = CarbonImmutable::now()->subDays(7)->startOfDay();
            $to = CarbonImmutable::now()->startOfDay();
        }

        if ($to->isBefore($from)) {
            $to = $from;
        }

        return [$from, $to->min($from->addDays(60))];
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function persistLine(ProductionSchedule $line, array $changes): mixed
    {
        $field = (string) array_key_first($changes);

        if (! $this->mayEnter($line)) {
            return $this->displayState($line, $field);
        }

        try {
            $actual = app(RecordProductionActualService::class)->record($line, $changes);
        } catch (RuntimeException $exception) {
            Notification::make()->danger()->title($exception->getMessage())->send();

            return $this->displayState($line->fresh(['latestActual']) ?? $line, $field);
        }

        $line->setRelation('latestActual', $actual);

        return $this->displayState($line, $field);
    }

    private function displayState(ProductionSchedule $line, string $field): mixed
    {
        $actual = $line->latestActual;

        if ($actual === null) {
            return null;
        }

        if ($field === 'manufactured_at') {
            return $actual->manufactured_at?->toDateString();
        }

        return $actual->getAttribute($field);
    }

    private function mayEnter(ProductionSchedule $line): bool
    {
        if (! $this->canEnter()) {
            Notification::make()
                ->danger()
                ->title('You can read actuals but not enter them.')
                ->send();

            return false;
        }

        $reachable = $this->reachableLine($line->id);

        if ($reachable === null) {
            return false;
        }

        $user = static::operationalUser();

        if ($user?->hasRole(config('filament-shield.super_admin.name'))) {
            return true;
        }

        if (! $this->canManageDepot(Capability::EnterProductionActuals, $reachable->depot)) {
            Notification::make()
                ->danger()
                ->title('That batch is not at a plant you can enter actuals for.')
                ->send();

            return false;
        }

        return true;
    }

    private function reachableLine(int $lineId): ?ProductionSchedule
    {
        $accessible = $this->accessibleDepotIds();

        $line = ProductionSchedule::query()
            ->with(['depot', 'sku', 'latestActual'])
            ->when($accessible !== null, fn (Builder $query) => $query->whereIn('depot_id', $accessible))
            ->find($lineId);

        if ($line === null) {
            Notification::make()
                ->danger()
                ->title('That batch is not in this window, or not at a plant you can see.')
                ->send();
        }

        return $line;
    }
}
