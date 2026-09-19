<?php

namespace App\Filament\Resources\NonProductionDays\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class NonProductionDayForm
{
    public const CATEGORIES = [
        'holiday' => 'Public holiday',
        'shutdown' => 'Plant shutdown',
        'stocktake' => 'Stocktake',
        'other' => 'Other',
    ];

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('depot_id')
                    ->label('Plant')
                    ->relationship('depot', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),
                DatePicker::make('date')
                    ->helperText('No shift runs at this plant on this day.')
                    ->required(),
                Select::make('category')
                    ->options(self::CATEGORIES)
                    ->default('holiday')
                    ->required(),
                TextInput::make('reason')
                    ->required()
                    ->maxLength(255),
            ]);
    }
}
