<?php

namespace App\Filament\Resources\MachineAssets\Tables;

use App\Filament\Resources\MachineAssets\Schemas\MachineAssetForm;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class MachineAssetsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultGroup('depot.name')
            ->defaultSort('code')
            ->columns([
                TextColumn::make('depot.name')
                    ->label('Plant')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('code')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('machine_type')
                    ->label('Type')
                    ->formatStateUsing(fn (string $state): string => MachineAssetForm::TYPES[$state] ?? $state)
                    ->badge(),
                TextColumn::make('run_rates_count')
                    ->label('SKUs qualified')
                    ->counts('runRates')
                    ->alignEnd()
                    ->tooltip('Products with a proven run rate on this machine. The planner only schedules these.'),
                IconColumn::make('is_active')
                    ->label('Planning')
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('depot')
                    ->label('Plant')
                    ->relationship('depot', 'name')
                    ->preload(),
                SelectFilter::make('machine_type')
                    ->label('Type')
                    ->options(MachineAssetForm::TYPES),
                TernaryFilter::make('is_active')
                    ->label('Available for planning'),
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
