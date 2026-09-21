<?php

namespace App\Filament\Resources\Depots;

use App\Enums\Capability;
use App\Filament\Concerns\ConfiguresPlantReferenceData;
use App\Filament\Resources\Depots\Pages\CreateDepot;
use App\Filament\Resources\Depots\Pages\EditDepot;
use App\Filament\Resources\Depots\Pages\ListDepots;
use App\Filament\Resources\Depots\Schemas\DepotForm;
use App\Filament\Resources\Depots\Tables\DepotsTable;
use App\Models\Depot;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class DepotResource extends Resource
{
    use ConfiguresPlantReferenceData;

    protected static ?string $model = Depot::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static string|UnitEnum|null $navigationGroup = 'Plant Configuration';

    protected static ?string $navigationLabel = 'Plants';

    protected static ?string $breadcrumb = 'Plants';

    protected static ?string $modelLabel = 'plant';

    protected static ?string $pluralModelLabel = 'plants';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return DepotForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DepotsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDepots::route('/'),
            'create' => CreateDepot::route('/create'),
            'edit' => EditDepot::route('/{record}/edit'),
        ];
    }

    /**
     * @return list<Capability>
     */
    protected static function viewCapabilities(): array
    {
        return [Capability::ViewMasterData];
    }

    protected static function manageCapability(): Capability
    {
        return Capability::ManageMasterData;
    }
}
