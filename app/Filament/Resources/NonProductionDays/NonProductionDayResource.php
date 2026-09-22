<?php

namespace App\Filament\Resources\NonProductionDays;

use App\Filament\Resources\NonProductionDays\Pages\CreateNonProductionDay;
use App\Filament\Resources\NonProductionDays\Pages\EditNonProductionDay;
use App\Filament\Resources\NonProductionDays\Pages\ListNonProductionDays;
use App\Filament\Resources\NonProductionDays\Schemas\NonProductionDayForm;
use App\Filament\Resources\NonProductionDays\Tables\NonProductionDaysTable;
use App\Models\NonProductionDay;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class NonProductionDayResource extends Resource
{
    protected static ?string $model = NonProductionDay::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|UnitEnum|null $navigationGroup = 'Plant Configuration';

    protected static ?string $navigationLabel = 'Shutdowns & holidays';

    protected static ?string $recordTitleAttribute = 'reason';

    protected static ?int $navigationSort = 40;

    public static function form(Schema $schema): Schema
    {
        return NonProductionDayForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return NonProductionDaysTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListNonProductionDays::route('/'),
            'create' => CreateNonProductionDay::route('/create'),
            'edit' => EditNonProductionDay::route('/{record}/edit'),
        ];
    }
}
