<?php

namespace App\Filament\Resources\OeeSettings\Tables;

use App\Models\OeeSetting;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class OeeSettingsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultGroup('depot.name')
            ->defaultSort('effective_from', 'desc')
            ->columns([
                TextColumn::make('depot.name')
                    ->label('Plant')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('scope')
                    ->label('Applies to')
                    ->state(fn (OeeSetting $record): string => match (true) {
                        $record->machine_asset_id !== null => $record->machine?->name ?? 'Machine',
                        $record->shift_id !== null => $record->shift?->name ?? 'Shift',
                        default => 'Whole plant',
                    })
                    ->badge()
                    ->color(fn (OeeSetting $record): string => match (true) {
                        $record->machine_asset_id !== null => 'warning',
                        $record->shift_id !== null => 'info',
                        default => 'gray',
                    }),
                TextColumn::make('effective_from')
                    ->label('In force from')
                    ->date('d M Y')
                    ->sortable(),
                TextColumn::make('performance_percent')
                    ->label('Perf.')
                    ->formatStateUsing(fn (string $state): string => rtrim(rtrim($state, '0'), '.').'%')
                    ->alignEnd(),
                TextColumn::make('availability_percent')
                    ->label('Avail.')
                    ->formatStateUsing(fn (string $state): string => rtrim(rtrim($state, '0'), '.').'%')
                    ->alignEnd(),
                TextColumn::make('overheads')
                    ->label('Time lost')
                    ->state(fn (OeeSetting $record): string => $record->nonProductiveMinutes().' min')
                    ->alignEnd(),
                TextColumn::make('effective_hours')
                    ->label('Effective hours')
                    ->state(fn (OeeSetting $record): string => self::effectiveHours($record))
                    ->tooltip('Shift length less the time lost, per shift this setting covers.'),
                TextColumn::make('notes')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->wrap(),
            ])
            ->filters([
                SelectFilter::make('depot')
                    ->label('Plant')
                    ->relationship('depot', 'name')
                    ->preload(),
            ])
            ->recordActions([
                EditAction::make(),
                // Raising a target creates a new dated row rather than editing history, so
                // plans built on the old figures still reconcile.
                Action::make('newVersion')
                    ->label('New version')
                    ->icon(Heroicon::OutlinedCalendarDays)
                    ->modalHeading('Copy these settings to a later date')
                    ->modalDescription('The existing row is left untouched. Adjust the copy once it is created.')
                    ->schema([
                        DatePicker::make('effective_from')
                            ->label('In force from')
                            ->default(now()->addMonth()->startOfMonth())
                            ->required(),
                    ])
                    ->action(function (OeeSetting $record, array $data): void {
                        $copy = $record->replicate();
                        $copy->effective_from = $data['effective_from'];
                        $copy->save();
                    })
                    ->successNotificationTitle('New version created'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    private static function effectiveHours(OeeSetting $setting): string
    {
        $overheads = $setting->nonProductiveMinutes();

        $shifts = $setting->shift_id !== null
            ? collect([$setting->shift])->filter()
            : $setting->depot->shifts->where('is_active', true);

        if ($shifts->isEmpty()) {
            return '—';
        }

        return $shifts
            ->sortBy('starts_at')
            ->map(fn ($shift): string => $shift->code.' '.round(max(0, (int) $shift->duration_minutes - $overheads) / 60, 2).' hr')
            ->implode(' · ');
    }
}
