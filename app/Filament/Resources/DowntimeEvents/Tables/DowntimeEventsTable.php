<?php

namespace App\Filament\Resources\DowntimeEvents\Tables;

use App\Filament\Resources\DowntimeEvents\Schemas\DowntimeEventForm;
use App\Models\DowntimeEvent;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class DowntimeEventsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('starts_at')
            ->columns([
                TextColumn::make('depot.name')
                    ->label('Plant')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('machine.name')
                    ->label('Machine')
                    ->placeholder('Whole plant')
                    ->searchable(),
                TextColumn::make('starts_at')
                    ->label('From')
                    ->dateTime('D d M Y H:i')
                    ->sortable(),
                TextColumn::make('ends_at')
                    ->label('To')
                    ->dateTime('D d M Y H:i')
                    ->sortable(),
                TextColumn::make('duration')
                    ->label('Duration')
                    ->state(fn (DowntimeEvent $record): string => round(
                        $record->starts_at->diffInMinutes($record->ends_at) / 60,
                        2,
                    ).' hr')
                    ->alignEnd(),
                TextColumn::make('category')
                    ->formatStateUsing(fn (string $state): string => DowntimeEventForm::CATEGORIES[$state] ?? $state)
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        DowntimeEvent::CATEGORY_BREAKDOWN => 'danger',
                        DowntimeEvent::CATEGORY_CHANGEOVER => 'warning',
                        default => 'gray',
                    }),
                IconColumn::make('is_planned')
                    ->label('Planned')
                    ->boolean(),
                TextColumn::make('origin')
                    ->badge()
                    ->color('gray')
                    ->tooltip('Change-overs written by the planner show as system.')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('description')
                    ->searchable()
                    ->wrap()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('depot')
                    ->label('Plant')
                    ->relationship('depot', 'name')
                    ->preload(),
                SelectFilter::make('category')
                    ->options(DowntimeEventForm::CATEGORIES),
                TernaryFilter::make('is_planned')
                    ->label('Planned'),
                Filter::make('upcoming')
                    ->label('Current and upcoming only')
                    ->default()
                    ->query(fn (Builder $query): Builder => $query->where('ends_at', '>=', now())),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
