<?php

namespace App\Filament\Resources\TransportCarriers\Schemas;

use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class TransportCarrierForm
{
    public const VEHICLE_KINDS = [
        'semi' => 'Semi',
        'rigid' => 'Rigid',
        'other' => 'Other',
    ];

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Carrier')
                    ->columns(2)
                    ->components([
                        TextInput::make('code')
                            ->required()
                            ->maxLength(40)
                            ->unique(ignoreRecord: true)
                            ->helperText('Short code used in exports, e.g. TODDS.'),
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('phone')
                            ->tel()
                            ->maxLength(40),
                        Toggle::make('is_active')
                            ->label('Active')
                            ->default(true),
                        Toggle::make('is_pickup')
                            ->label('Pickup / Ex Works')
                            ->helperText('No carrier email; freight estimate is zero.')
                            ->default(false),
                        Textarea::make('notes')
                            ->rows(3)
                            ->columnSpanFull(),
                    ]),
                Section::make('Schedule emails')
                    ->description('Used when sending the daily delivery schedule to this carrier.')
                    ->components([
                        Repeater::make('emails')
                            ->relationship()
                            ->hiddenLabel()
                            ->defaultItems(0)
                            ->addActionLabel('Add email')
                            ->columns(3)
                            ->components([
                                TextInput::make('email')
                                    ->email()
                                    ->required()
                                    ->maxLength(255),
                                TextInput::make('label')
                                    ->maxLength(80),
                                Toggle::make('is_primary')
                                    ->label('Primary')
                                    ->inline(false),
                            ]),
                    ]),
                Section::make('Vehicles')
                    ->description('Capacity and minimum billable tonnes drive load suggestions and freight estimates.')
                    ->components([
                        Repeater::make('vehicles')
                            ->relationship()
                            ->hiddenLabel()
                            ->defaultItems(0)
                            ->addActionLabel('Add vehicle')
                            ->columns(3)
                            ->components([
                                TextInput::make('name')
                                    ->required()
                                    ->maxLength(80),
                                Select::make('vehicle_kind')
                                    ->options(self::VEHICLE_KINDS)
                                    ->default('semi')
                                    ->required(),
                                TextInput::make('capacity_tonnes')
                                    ->numeric()
                                    ->minValue(0.001)
                                    ->required(),
                                TextInput::make('minimum_billable_tonnes')
                                    ->numeric()
                                    ->minValue(0)
                                    ->default(0),
                                Toggle::make('is_default')
                                    ->label('Default')
                                    ->inline(false),
                            ]),
                    ]),
                Section::make('Zone rates')
                    ->description('Rates are per supplying entity and ERP freight zone.')
                    ->components([
                        Repeater::make('zoneRates')
                            ->relationship()
                            ->hiddenLabel()
                            ->defaultItems(0)
                            ->addActionLabel('Add zone rate')
                            ->columns(3)
                            ->components([
                                TextInput::make('erp_entity_code')
                                    ->label('Entity')
                                    ->required()
                                    ->maxLength(20),
                                TextInput::make('zone_code')
                                    ->required()
                                    ->maxLength(40),
                                TextInput::make('rate_per_tonne')
                                    ->numeric()
                                    ->minValue(0)
                                    ->required(),
                                TextInput::make('minimum_charge_amount')
                                    ->numeric()
                                    ->minValue(0),
                                Toggle::make('is_active')
                                    ->label('Active')
                                    ->default(true)
                                    ->inline(false),
                            ]),
                    ]),
            ]);
    }

}
