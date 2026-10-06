<?php

namespace App\Filament\Resources\SkuMasters\Tables;

use App\Filament\Resources\SkuMasters\Schemas\SkuMasterForm;
use App\Models\SkuMaster;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class SkuMastersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('sku_code')
            ->columns([
                TextColumn::make('sku_code')
                    ->label('Tencia code')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('description')
                    ->searchable()
                    ->wrap()
                    ->limit(40),
                TextColumn::make('erp_entity_code')
                    ->label('Entity')
                    ->badge()
                    ->color('gray')
                    ->placeholder('Not mapped')
                    ->sortable(),
                TextColumn::make('mould_type')
                    ->placeholder('—'),
                TextColumn::make('reo_cage')
                    ->label('Reo cage')
                    ->formatStateUsing(fn (?string $state): string => SkuMasterForm::REO_CAGES[$state] ?? ucfirst((string) $state))
                    ->placeholder('—'),
                TextColumn::make('moulds_count')
                    ->label('Moulds #')
                    ->alignEnd()
                    ->placeholder('—'),
                TextColumn::make('pallets_count')
                    ->label('Pallets #')
                    ->alignEnd()
                    ->placeholder('—'),
                IconColumn::make('is_active')
                    ->label('Planning')
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('erp_entity_code')
                    ->label('Entity')
                    ->options(fn (): array => SkuMaster::query()
                        ->whereNotNull('erp_entity_code')
                        ->orderBy('erp_entity_code')
                        ->distinct()
                        ->pluck('erp_entity_code', 'erp_entity_code')
                        ->all()),
                SelectFilter::make('reo_cage')
                    ->label('Reo cage')
                    ->options(SkuMasterForm::REO_CAGES),
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
