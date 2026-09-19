<?php

namespace App\Filament\Resources\Shifts\Schemas;

use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class ShiftForm
{
    /** Sunday is 0, matching Carbon::dayOfWeek. */
    public const WEEKDAYS = [
        1 => 'Monday',
        2 => 'Tuesday',
        3 => 'Wednesday',
        4 => 'Thursday',
        5 => 'Friday',
        6 => 'Saturday',
        0 => 'Sunday',
    ];

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Shift')
                    ->columns(2)
                    ->components([
                        Select::make('depot_id')
                            ->label('Plant')
                            ->relationship('depot', 'name')
                            ->searchable()
                            ->preload()
                            ->required(),
                        TextInput::make('code')
                            ->helperText('Unique within the plant, e.g. DAY or AFT.')
                            ->required()
                            ->maxLength(30),
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        TimePicker::make('starts_at')
                            ->label('Starts at')
                            ->seconds(false)
                            ->required(),
                        TextInput::make('duration_minutes')
                            ->label('Default length (minutes)')
                            ->helperText('Used on any weekday without its own length below.')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(1440)
                            ->required()
                            ->live(onBlur: true)
                            ->suffix(fn (?string $state): string => self::asHours($state)),
                        Toggle::make('is_active')
                            ->label('Available for planning')
                            ->default(true),
                    ]),

                Section::make('Hours per weekday')
                    ->description('Add a row for each day this shift runs. A day with no row does not run — that is how weekends stay out of the plan. Melton and Kilmore afternoons, for example, run 600 minutes on Monday and Friday and 720 from Tuesday to Thursday.')
                    ->components([
                        Repeater::make('patterns')
                            ->relationship()
                            ->hiddenLabel()
                            ->columns(3)
                            ->addActionLabel('Add a day')
                            ->defaultItems(0)
                            ->orderColumn(false)
                            ->components([
                                Select::make('weekday')
                                    ->options(self::WEEKDAYS)
                                    ->required()
                                    ->distinct()
                                    ->fixIndistinctState(),
                                TextInput::make('duration_minutes')
                                    ->label('Length (minutes)')
                                    ->numeric()
                                    ->minValue(1)
                                    ->maxValue(1440)
                                    ->required()
                                    ->live(onBlur: true)
                                    ->suffix(fn (?string $state): string => self::asHours($state)),
                                Toggle::make('is_active')
                                    ->label('Runs this day')
                                    ->default(true)
                                    ->inline(false),
                            ])
                            ->itemLabel(fn (array $state): ?string => isset($state['weekday'])
                                ? self::WEEKDAYS[(int) $state['weekday']] ?? null
                                : null),
                    ]),

                Section::make('Fallback OEE targets')
                    ->description('Used only when no OEE setting matches this shift. Prefer OEE Settings, which are dated and can be scoped to a single machine.')
                    ->collapsed(fn (Get $get): bool => $get('id') !== null)
                    ->columns(4)
                    ->components([
                        TextInput::make('performance_target_percent')
                            ->label('Performance %')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(100)
                            ->default(100)
                            ->required(),
                        TextInput::make('availability_target_percent')
                            ->label('Availability %')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(100)
                            ->default(100)
                            ->required(),
                        TextInput::make('prestart_minutes')
                            ->label('Prestart (min)')
                            ->numeric()
                            ->minValue(0)
                            ->default(0)
                            ->required(),
                        TextInput::make('cleaning_minutes')
                            ->label('Cleaning (min)')
                            ->numeric()
                            ->minValue(0)
                            ->default(0)
                            ->required(),
                    ]),
            ]);
    }

    private static function asHours(?string $minutes): string
    {
        $value = (int) $minutes;

        return $value > 0 ? round($value / 60, 2).' hr' : '';
    }
}
