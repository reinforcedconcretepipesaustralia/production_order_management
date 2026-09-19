<?php

namespace App\Filament\Resources\DowntimeEvents\Schemas;

use App\Models\DowntimeEvent;
use App\Models\MachineAsset;
use App\Models\Shift;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class DowntimeEventForm
{
    public const CATEGORIES = [
        DowntimeEvent::CATEGORY_MAINTENANCE => 'Planned maintenance',
        DowntimeEvent::CATEGORY_CHANGEOVER => 'Mould or head change-over',
        DowntimeEvent::CATEGORY_SHUTDOWN => 'Shutdown',
        DowntimeEvent::CATEGORY_BREAKDOWN => 'Breakdown',
    ];

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('What is down')
                    ->description('Leave the machine blank if the whole plant is down, and the shift blank if it spans shifts. These minutes come off available capacity.')
                    ->columns(3)
                    ->components([
                        Select::make('depot_id')
                            ->label('Plant')
                            ->relationship('depot', 'name')
                            ->searchable()
                            ->preload()
                            ->required()
                            ->live(),
                        Select::make('machine_asset_id')
                            ->label('Machine')
                            ->placeholder('Whole plant')
                            ->options(fn (Get $get): array => $get('depot_id') === null
                                ? []
                                : MachineAsset::query()
                                    ->where('depot_id', $get('depot_id'))
                                    ->orderBy('code')
                                    ->pluck('name', 'id')
                                    ->all())
                            ->searchable(),
                        Select::make('shift_id')
                            ->label('Shift')
                            ->placeholder('Any shift it overlaps')
                            ->options(fn (Get $get): array => $get('depot_id') === null
                                ? []
                                : Shift::query()
                                    ->where('depot_id', $get('depot_id'))
                                    ->orderBy('starts_at')
                                    ->pluck('name', 'id')
                                    ->all())
                            ->searchable(),
                    ]),

                Section::make('When')
                    ->columns(2)
                    ->components([
                        DateTimePicker::make('starts_at')
                            ->seconds(false)
                            ->required(),
                        DateTimePicker::make('ends_at')
                            ->seconds(false)
                            ->required()
                            ->after('starts_at'),
                    ]),

                Section::make('Detail')
                    ->columns(2)
                    ->components([
                        Select::make('category')
                            ->options(self::CATEGORIES)
                            ->default(DowntimeEvent::CATEGORY_MAINTENANCE)
                            ->required(),
                        TextInput::make('description')
                            ->required()
                            ->maxLength(255),
                        Toggle::make('is_planned')
                            ->label('Planned')
                            ->helperText('Turn off to record an unplanned breakdown after the fact.')
                            ->default(true),
                    ]),
            ]);
    }
}
