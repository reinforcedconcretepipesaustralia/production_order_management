<?php

namespace App\Filament\Resources\SchedulingRules\Tables;

use App\Filament\Resources\SchedulingRules\SchedulingRuleResource;
use App\Filament\Resources\SchedulingRules\Schemas\SchedulingRuleForm;
use App\Models\SchedulingRule;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class SchedulingRulesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->recordUrl(fn (SchedulingRule $record): string => SchedulingRuleResource::getUrl('edit', ['record' => $record]))
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->wrap(),
                TextColumn::make('code')
                    ->badge()
                    ->color('gray')
                    ->copyable(),
                TextColumn::make('category')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => SchedulingRuleForm::CATEGORIES[$state] ?? $state),
                TextColumn::make('value')
                    ->alignEnd()
                    ->placeholder('—')
                    ->formatStateUsing(function (mixed $state): ?string {
                        if ($state === null || $state === '') {
                            return null;
                        }

                        return rtrim(rtrim(number_format((float) $state, 4, '.', ''), '0'), '.');
                    }),
                TextColumn::make('description')
                    ->wrap()
                    ->limit(80),
                IconColumn::make('is_active')
                    ->label('Used')
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('category')
                    ->options(SchedulingRuleForm::CATEGORIES),
                TernaryFilter::make('is_active')
                    ->label('Used when planning'),
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
