<?php

namespace App\Filament\Resources\Shifts;

use App\Enums\Capability;
use App\Filament\Concerns\ConfiguresPlantReferenceData;
use App\Filament\Resources\Shifts\Pages\CreateShift;
use App\Filament\Resources\Shifts\Pages\EditShift;
use App\Filament\Resources\Shifts\Pages\ListShifts;
use App\Filament\Resources\Shifts\Schemas\ShiftForm;
use App\Filament\Resources\Shifts\Tables\ShiftsTable;
use App\Models\Shift;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class ShiftResource extends Resource
{
    use ConfiguresPlantReferenceData;

    protected static ?string $model = Shift::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static string|UnitEnum|null $navigationGroup = 'Plant Configuration';

    protected static ?string $navigationLabel = 'Shifts & hours';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 10;

    public static function form(Schema $schema): Schema
    {
        return ShiftForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ShiftsTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['depot', 'patterns']);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListShifts::route('/'),
            'create' => CreateShift::route('/create'),
            'edit' => EditShift::route('/{record}/edit'),
        ];
    }

    /**
     * @return list<Capability>
     */
    protected static function viewCapabilities(): array
    {
        return [Capability::ViewMasterData, Capability::ViewSchedules];
    }

    protected static function manageCapability(): Capability
    {
        return Capability::ManageMasterData;
    }
}
