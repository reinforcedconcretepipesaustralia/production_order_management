<?php

namespace App\Filament\Resources\SchedulingRules\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class SchedulingRuleForm
{
    public const CATEGORIES = [
        'capacity' => 'Capacity',
        'inventory' => 'Inventory',
        'sequencing' => 'Sequencing',
        'guidance' => 'Guidance',
    ];

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                TextInput::make('code')
                    ->helperText('The planner looks up max_runs_per_shift, minimum_run_fraction, target_months_on_hand and include_replenishment. Any other code is guidance for the plan explanation.')
                    ->required()
                    ->maxLength(80)
                    ->unique(ignoreRecord: true),
                Select::make('category')
                    ->options(self::CATEGORIES)
                    ->required(),
                TextInput::make('value')
                    ->numeric()
                    ->helperText('Used when the code is one the engine understands. Leave blank for a written rule.'),
                TextInput::make('sort_order')
                    ->numeric()
                    ->minValue(0)
                    ->default(100)
                    ->required(),
                Toggle::make('is_active')
                    ->label('Used when planning')
                    ->default(true),
                Textarea::make('description')
                    ->required()
                    ->rows(3)
                    ->columnSpanFull()
                    ->helperText('Write it the way a scheduler would say it on the floor.'),
            ]);
    }
}
