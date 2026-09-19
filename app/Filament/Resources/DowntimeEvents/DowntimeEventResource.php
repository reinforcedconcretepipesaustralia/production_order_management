<?php

namespace App\Filament\Resources\DowntimeEvents;

use App\Enums\Capability;
use App\Filament\Concerns\ConfiguresPlantReferenceData;
use App\Filament\Resources\DowntimeEvents\Pages\CreateDowntimeEvent;
use App\Filament\Resources\DowntimeEvents\Pages\EditDowntimeEvent;
use App\Filament\Resources\DowntimeEvents\Pages\ListDowntimeEvents;
use App\Filament\Resources\DowntimeEvents\Schemas\DowntimeEventForm;
use App\Filament\Resources\DowntimeEvents\Tables\DowntimeEventsTable;
use App\Models\DowntimeEvent;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class DowntimeEventResource extends Resource
{
    use ConfiguresPlantReferenceData;

    protected static ?string $model = DowntimeEvent::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWrenchScrewdriver;

    protected static string|UnitEnum|null $navigationGroup = 'Plant Configuration';

    protected static ?string $navigationLabel = 'Downtime & maintenance';

    protected static ?string $recordTitleAttribute = 'description';

    protected static ?int $navigationSort = 50;

    public static function form(Schema $schema): Schema
    {
        return DowntimeEventForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DowntimeEventsTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['depot', 'machine']);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDowntimeEvents::route('/'),
            'create' => CreateDowntimeEvent::route('/create'),
            'edit' => EditDowntimeEvent::route('/{record}/edit'),
        ];
    }

    /**
     * @return list<Capability>
     */
    protected static function viewCapabilities(): array
    {
        return [Capability::ViewSchedules, Capability::ViewAssets];
    }

    protected static function manageCapability(): Capability
    {
        return Capability::ManageSchedules;
    }
}
