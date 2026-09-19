<?php

namespace App\Filament\Resources\OeeSettings\Schemas;

use App\Models\MachineAsset;
use App\Models\Shift;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class OeeSettingForm
{
    /** Overhead fields, in the order the workbooks' OEE Settings tab lists them. */
    public const OVERHEAD_FIELDS = [
        'prestart_minutes' => 'Prestart',
        'paid_break_minutes' => 'Paid break',
        'unpaid_break_minutes' => 'Unpaid break',
        'in_shift_cleaning_minutes' => 'In-shift cleaning',
        'end_of_shift_cleaning_minutes' => 'End-of-shift cleaning',
    ];

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Applies to')
                    ->description('Leave the shift or machine blank to cover everything below it. The most specific match wins: machine, then shift, then the whole plant.')
                    ->columns(2)
                    ->components([
                        Select::make('depot_id')
                            ->label('Plant')
                            ->relationship('depot', 'name')
                            ->searchable()
                            ->preload()
                            ->required()
                            ->live(),
                        DatePicker::make('effective_from')
                            ->label('In force from')
                            ->helperText('Earlier settings are kept, so plans built before this date still reconcile.')
                            ->default(now()->startOfMonth())
                            ->required(),
                        Select::make('shift_id')
                            ->label('Shift')
                            ->placeholder('All shifts at this plant')
                            ->options(fn (Get $get): array => $get('depot_id') === null
                                ? []
                                : Shift::query()
                                    ->where('depot_id', $get('depot_id'))
                                    ->orderBy('starts_at')
                                    ->pluck('name', 'id')
                                    ->all())
                            ->searchable(),
                        Select::make('machine_asset_id')
                            ->label('Machine')
                            ->placeholder('All machines')
                            ->options(fn (Get $get): array => $get('depot_id') === null
                                ? []
                                : MachineAsset::query()
                                    ->where('depot_id', $get('depot_id'))
                                    ->orderBy('code')
                                    ->pluck('name', 'id')
                                    ->all())
                            ->searchable(),
                    ]),

                Section::make('OEE')
                    ->description('Planned pipes are the machine run rate multiplied by performance, availability and the effective hours below. Quality is recorded for reporting but is not planned on — rejects are captured as dumped pipes instead.')
                    ->columns(3)
                    ->components([
                        TextInput::make('performance_percent')
                            ->label('Performance %')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(100)
                            ->required(),
                        TextInput::make('availability_percent')
                            ->label('Availability %')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(100)
                            ->required(),
                        TextInput::make('quality_percent')
                            ->label('Quality % (reporting only)')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(100),
                    ]),

                Section::make('Time lost each shift')
                    ->description('Deducted from the shift length to give effective hours.')
                    ->columns(5)
                    ->components([
                        ...array_map(
                            fn (string $label, string $field) => TextInput::make($field)
                                ->label("{$label} (min)")
                                ->numeric()
                                ->minValue(0)
                                ->default(0)
                                ->required()
                                ->live(onBlur: true),
                            self::OVERHEAD_FIELDS,
                            array_keys(self::OVERHEAD_FIELDS),
                        ),
                    ]),

                Section::make()
                    ->components([
                        Text::make(fn (Get $get): string => self::effectiveHoursSummary($get))
                            ->weight('medium'),
                        Textarea::make('notes')
                            ->rows(2)
                            ->maxLength(255),
                    ]),
            ]);
    }

    /**
     * Shows the effective hours these overheads produce for each shift they would apply to,
     * so a change is visible before it is saved.
     */
    private static function effectiveHoursSummary(Get $get): string
    {
        $overheads = 0;

        foreach (array_keys(self::OVERHEAD_FIELDS) as $field) {
            $overheads += (int) $get($field);
        }

        $depotId = $get('depot_id');

        if ($depotId === null) {
            return "Time lost each shift: {$overheads} min. Choose a plant to see the effective hours.";
        }

        $shifts = Shift::query()
            ->where('depot_id', $depotId)
            ->when($get('shift_id') !== null, fn ($query) => $query->whereKey($get('shift_id')))
            ->where('is_active', true)
            ->orderBy('starts_at')
            ->get();

        if ($shifts->isEmpty()) {
            return "Time lost each shift: {$overheads} min. No active shifts at this plant yet.";
        }

        $summary = $shifts
            ->map(function (Shift $shift) use ($overheads): string {
                $effective = max(0, (int) $shift->duration_minutes - $overheads);

                return $shift->code.' '.round($effective / 60, 2).' hr';
            })
            ->implode(' · ');

        return "Time lost each shift: {$overheads} min → effective hours {$summary}";
    }
}
