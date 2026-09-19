<?php

namespace App\Filament\Resources\OeeSettings;

use App\Enums\Capability;
use App\Filament\Concerns\ConfiguresPlantReferenceData;
use App\Filament\Resources\OeeSettings\Pages\CreateOeeSetting;
use App\Filament\Resources\OeeSettings\Pages\EditOeeSetting;
use App\Filament\Resources\OeeSettings\Pages\ListOeeSettings;
use App\Filament\Resources\OeeSettings\Schemas\OeeSettingForm;
use App\Filament\Resources\OeeSettings\Tables\OeeSettingsTable;
use App\Models\OeeSetting;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class OeeSettingResource extends Resource
{
    use ConfiguresPlantReferenceData;

    protected static ?string $model = OeeSetting::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static string|UnitEnum|null $navigationGroup = 'Plant Configuration';

    protected static ?string $navigationLabel = 'OEE settings';

    protected static ?int $navigationSort = 30;

    public static function form(Schema $schema): Schema
    {
        return OeeSettingForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return OeeSettingsTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['depot.shifts', 'shift', 'machine']);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOeeSettings::route('/'),
            'create' => CreateOeeSetting::route('/create'),
            'edit' => EditOeeSetting::route('/{record}/edit'),
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
