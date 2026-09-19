<?php

namespace App\Filament\Resources\Depots\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class DepotForm
{
    public const STATES = [
        'VIC' => 'Victoria',
        'NSW' => 'New South Wales',
        'QLD' => 'Queensland',
        'SA' => 'South Australia',
        'WA' => 'Western Australia',
        'TAS' => 'Tasmania',
        'NT' => 'Northern Territory',
        'ACT' => 'Australian Capital Territory',
    ];

    public const TIMEZONES = [
        'Australia/Melbourne' => 'Melbourne (VIC)',
        'Australia/Sydney' => 'Sydney (NSW/ACT)',
        'Australia/Brisbane' => 'Brisbane (QLD)',
        'Australia/Adelaide' => 'Adelaide (SA)',
        'Australia/Perth' => 'Perth (WA)',
        'Australia/Hobart' => 'Hobart (TAS)',
        'Australia/Darwin' => 'Darwin (NT)',
    ];

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Plant')
                    ->columns(2)
                    ->components([
                        TextInput::make('code')
                            ->helperText('Short code used across the app, e.g. MEL.')
                            ->required()
                            ->maxLength(20)
                            ->unique(ignoreRecord: true),
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        Select::make('state')
                            ->options(self::STATES)
                            ->required(),
                        Select::make('timezone')
                            ->options(self::TIMEZONES)
                            ->default('Australia/Melbourne')
                            ->required(),
                        Toggle::make('is_active')
                            ->default(true),
                        Toggle::make('is_planning_enabled')
                            ->label('Include in plan generation')
                            ->helperText('Leave off until the plant has shifts, machines, run rates and OEE settings.')
                            ->default(false),
                    ]),

                Section::make('Tencia mapping')
                    ->description('How imports recognise this plant. Stock location codes only mean something within an entity, so the same code can appear at plants in different states.')
                    ->columns(2)
                    ->components([
                        TextInput::make('erp_entity_code')
                            ->label('Entity code')
                            ->helperText('Matches the ENTITY column on the TenciaRCP_View views, e.g. VIC.')
                            ->maxLength(20),
                        TagsInput::make('erp_stock_categories')
                            ->label('Stock categories')
                            ->helperText('e.g. VA, VB, VD. Press Enter after each.')
                            ->placeholder('Add a category'),
                        TagsInput::make('prime_stock_locations')
                            ->label('Prime stock locations')
                            ->helperText('Locations holding good stock.')
                            ->placeholder('Add a location'),
                        TagsInput::make('rework_stock_locations')
                            ->label('Rework stock locations')
                            ->placeholder('Add a location'),
                    ]),
            ]);
    }
}
