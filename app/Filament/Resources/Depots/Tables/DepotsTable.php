<?php

namespace App\Filament\Resources\Depots\Tables;

use App\Filament\Resources\Depots\Schemas\DepotForm;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class DepotsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('code')
            ->columns([
                TextColumn::make('code')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('name')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('state')
                    ->badge()
                    ->sortable(),
                TextColumn::make('erp_entity_code')
                    ->label('Entity')
                    ->badge()
                    ->color('gray')
                    ->placeholder('Not mapped'),
                TextColumn::make('shifts_count')
                    ->label('Shifts')
                    ->counts(['shifts' => fn ($query) => $query->where('is_active', true)])
                    ->alignEnd(),
                TextColumn::make('machines_count')
                    ->label('Machines')
                    ->counts(['machines' => fn ($query) => $query->where('is_active', true)])
                    ->alignEnd(),
                IconColumn::make('is_planning_enabled')
                    ->label('In planning')
                    ->boolean(),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('state')
                    ->options(DepotForm::STATES),
                TernaryFilter::make('is_planning_enabled')
                    ->label('Included in plan generation'),
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
