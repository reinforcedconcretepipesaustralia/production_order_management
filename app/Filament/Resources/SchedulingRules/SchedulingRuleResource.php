<?php

namespace App\Filament\Resources\SchedulingRules;

use App\Filament\Resources\SchedulingRules\Pages\CreateSchedulingRule;
use App\Filament\Resources\SchedulingRules\Pages\EditSchedulingRule;
use App\Filament\Resources\SchedulingRules\Pages\ListSchedulingRules;
use App\Filament\Resources\SchedulingRules\Schemas\SchedulingRuleForm;
use App\Filament\Resources\SchedulingRules\Tables\SchedulingRulesTable;
use App\Models\SchedulingRule;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class SchedulingRuleResource extends Resource
{
    protected static ?string $model = SchedulingRule::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedListBullet;

    protected static string|UnitEnum|null $navigationGroup = 'Plant Configuration';

    protected static ?string $navigationLabel = 'Scheduling rules';

    protected static ?string $breadcrumb = 'Scheduling rules';

    protected static ?string $modelLabel = 'scheduling rule';

    protected static ?string $pluralModelLabel = 'scheduling rules';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 12;

    public static function form(Schema $schema): Schema
    {
        return SchedulingRuleForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SchedulingRulesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSchedulingRules::route('/'),
            'create' => CreateSchedulingRule::route('/create'),
            'edit' => EditSchedulingRule::route('/{record}/edit'),
        ];
    }
}
