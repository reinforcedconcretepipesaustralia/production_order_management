<?php

namespace App\Filament\Resources\Shifts\Tables;

use App\Filament\Resources\Shifts\Schemas\ShiftForm;
use App\Models\DepotShiftPattern;
use App\Models\Shift;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class ShiftsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultGroup('depot.name')
            ->defaultSort('starts_at')
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
                TextColumn::make('starts_at')
                    ->label('Starts')
                    ->formatStateUsing(fn (string $state): string => substr($state, 0, 5))
                    ->sortable(),
                TextColumn::make('duration_minutes')
                    ->label('Default')
                    ->formatStateUsing(fn (int $state): string => round($state / 60, 2).' hr')
                    ->alignEnd(),
                TextColumn::make('patterns')
                    ->label('Hours per weekday')
                    ->state(fn (Shift $record): string => self::weekdaySummary($record))
                    ->tooltip('A weekday with no entry does not run.')
                    ->wrap(),
                IconColumn::make('is_active')
                    ->label('Planning')
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('depot')
                    ->label('Plant')
                    ->relationship('depot', 'name')
                    ->preload(),
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

    /**
     * Collapses the weekday patterns into something readable, e.g. 'Mon-Fri 10 hr' or
     * 'Mon 10, Tue-Thu 12, Fri 10 hr'.
     */
    private static function weekdaySummary(Shift $shift): string
    {
        $patterns = $shift->patterns
            ->where('is_active', true)
            ->sortBy(fn (DepotShiftPattern $pattern): int => $pattern->weekday === 0 ? 7 : $pattern->weekday);

        if ($patterns->isEmpty()) {
            return 'Every weekday at the default length';
        }

        $groups = [];

        foreach ($patterns as $pattern) {
            $hours = (string) round($pattern->duration_minutes / 60, 2);
            $day = substr(ShiftForm::WEEKDAYS[$pattern->weekday] ?? '?', 0, 3);
            $last = array_key_last($groups);

            if ($last !== null && $groups[$last]['hours'] === $hours) {
                $groups[$last]['to'] = $day;

                continue;
            }

            $groups[] = ['from' => $day, 'to' => null, 'hours' => $hours];
        }

        return collect($groups)
            ->map(fn (array $group): string => ($group['to'] === null
                ? $group['from']
                : "{$group['from']}-{$group['to']}")." {$group['hours']} hr")
            ->implode(', ');
    }
}
