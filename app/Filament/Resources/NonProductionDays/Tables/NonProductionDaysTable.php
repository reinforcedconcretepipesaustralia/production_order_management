<?php

namespace App\Filament\Resources\NonProductionDays\Tables;

use App\Filament\Resources\NonProductionDays\Schemas\NonProductionDayForm;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class NonProductionDaysTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('date')
            ->columns([
                TextColumn::make('depot.name')
                    ->label('Plant')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('date')
                    ->date('D d M Y')
                    ->sortable(),
                TextColumn::make('category')
                    ->formatStateUsing(fn (string $state): string => NonProductionDayForm::CATEGORIES[$state] ?? $state)
                    ->badge(),
                TextColumn::make('reason')
                    ->searchable()
                    ->wrap(),
            ])
            ->filters([
                SelectFilter::make('depot')
                    ->label('Plant')
                    ->relationship('depot', 'name')
                    ->preload(),
                SelectFilter::make('category')
                    ->options(NonProductionDayForm::CATEGORIES),
                Filter::make('upcoming')
                    ->label('Upcoming only')
                    ->default()
                    ->query(fn (Builder $query): Builder => $query->where('date', '>=', today())),
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
