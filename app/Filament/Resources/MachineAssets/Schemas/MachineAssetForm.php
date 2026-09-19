<?php

namespace App\Filament\Resources\MachineAssets\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class MachineAssetForm
{
    /**
     * Machine types seen across the three state workbooks. 'bidi' covers the BiDi 36 and
     * BiDi 84 at Melton and Yatala; the other plants run a single pipe machine.
     */
    public const TYPES = [
        'bidi' => 'BiDi',
        'pipe_machine' => 'Pipe machine',
        'cage_machine' => 'Cage machine',
        'other' => 'Other',
    ];

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('depot_id')
                    ->label('Plant')
                    ->relationship('depot', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),
                TextInput::make('code')
                    ->label('Code')
                    ->helperText('Unique across all plants, e.g. MEL-BIDI36.')
                    ->required()
                    ->maxLength(50)
                    ->unique(ignoreRecord: true),
                TextInput::make('name')
                    ->helperText('As production refers to it, e.g. BiDi 84.')
                    ->required()
                    ->maxLength(255),
                Select::make('machine_type')
                    ->options(self::TYPES)
                    ->default('bidi')
                    ->required(),
                Toggle::make('is_active')
                    ->label('Available for planning')
                    ->helperText('Turn off to keep the machine and its history without scheduling onto it.')
                    ->default(true),
            ]);
    }
}
