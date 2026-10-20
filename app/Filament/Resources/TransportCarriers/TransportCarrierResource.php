<?php

namespace App\Filament\Resources\TransportCarriers;

use App\Filament\Resources\TransportCarriers\Pages\CreateTransportCarrier;
use App\Filament\Resources\TransportCarriers\Pages\EditTransportCarrier;
use App\Filament\Resources\TransportCarriers\Pages\ListTransportCarriers;
use App\Filament\Resources\TransportCarriers\Schemas\TransportCarrierForm;
use App\Filament\Resources\TransportCarriers\Tables\TransportCarriersTable;
use App\Models\TransportCarrier;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class TransportCarrierResource extends Resource
{
    protected static ?string $model = TransportCarrier::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static string|UnitEnum|null $navigationGroup = 'Logistics';

    protected static ?string $navigationLabel = 'Transport carriers';

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return TransportCarrierForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TransportCarriersTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTransportCarriers::route('/'),
            'create' => CreateTransportCarrier::route('/create'),
            'edit' => EditTransportCarrier::route('/{record}/edit'),
        ];
    }
}
