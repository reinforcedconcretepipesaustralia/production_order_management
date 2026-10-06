<?php

namespace App\Filament\Resources\SkuMasters;

use App\Filament\Resources\SkuMasters\Pages\CreateSkuMaster;
use App\Filament\Resources\SkuMasters\Pages\EditSkuMaster;
use App\Filament\Resources\SkuMasters\Pages\ListSkuMasters;
use App\Filament\Resources\SkuMasters\Schemas\SkuMasterForm;
use App\Filament\Resources\SkuMasters\Tables\SkuMastersTable;
use App\Models\SkuMaster;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class SkuMasterResource extends Resource
{
    protected static ?string $model = SkuMaster::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCube;

    protected static string|UnitEnum|null $navigationGroup = 'Plant Configuration';

    protected static ?string $navigationLabel = 'Products';

    protected static ?string $breadcrumb = 'Products';

    protected static ?string $modelLabel = 'product';

    protected static ?string $pluralModelLabel = 'products';

    protected static ?string $recordTitleAttribute = 'sku_code';

    protected static ?int $navigationSort = 15;

    public static function form(Schema $schema): Schema
    {
        return SkuMasterForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SkuMastersTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSkuMasters::route('/'),
            'create' => CreateSkuMaster::route('/create'),
            'edit' => EditSkuMaster::route('/{record}/edit'),
        ];
    }
}
