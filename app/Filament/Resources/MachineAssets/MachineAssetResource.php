<?php

namespace App\Filament\Resources\MachineAssets;

use App\Enums\Capability;
use App\Filament\Concerns\ConfiguresPlantReferenceData;
use App\Filament\Resources\MachineAssets\Pages\CreateMachineAsset;
use App\Filament\Resources\MachineAssets\Pages\EditMachineAsset;
use App\Filament\Resources\MachineAssets\Pages\ListMachineAssets;
use App\Filament\Resources\MachineAssets\Schemas\MachineAssetForm;
use App\Filament\Resources\MachineAssets\Tables\MachineAssetsTable;
use App\Models\MachineAsset;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class MachineAssetResource extends Resource
{
    use ConfiguresPlantReferenceData;

    protected static ?string $model = MachineAsset::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static string|UnitEnum|null $navigationGroup = 'Plant Configuration';

    protected static ?string $navigationLabel = 'Machines';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 20;

    public static function form(Schema $schema): Schema
    {
        return MachineAssetForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MachineAssetsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMachineAssets::route('/'),
            'create' => CreateMachineAsset::route('/create'),
            'edit' => EditMachineAsset::route('/{record}/edit'),
        ];
    }

    /**
     * @return list<Capability>
     */
    protected static function viewCapabilities(): array
    {
        return [Capability::ViewAssets, Capability::ViewMasterData];
    }

    protected static function manageCapability(): Capability
    {
        return Capability::ManageAssets;
    }
}
