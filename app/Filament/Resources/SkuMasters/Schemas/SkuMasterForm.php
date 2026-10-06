<?php

namespace App\Filament\Resources\SkuMasters\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

class SkuMasterForm
{
    public const REO_CAGES = [
        'single' => 'Single',
        'double' => 'Double',
    ];

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('sku_code')
                    ->label('Tencia code')
                    ->required()
                    ->maxLength(50)
                    ->disabledOn('edit')
                    ->rules([
                        fn (Get $get, mixed $record): Unique => Rule::unique('sku_master', 'sku_code')
                            ->where('erp_entity_code', $get('erp_entity_code'))
                            ->ignore($record),
                    ]),
                TextInput::make('erp_entity_code')
                    ->label('Entity')
                    ->helperText('Tencia entity code, e.g. VIC. The same stock code can exist in more than one state.')
                    ->required()
                    ->maxLength(20)
                    ->disabledOn('edit'),
                TextInput::make('description')
                    ->required()
                    ->maxLength(255)
                    ->columnSpanFull(),
                TextInput::make('mould_type')
                    ->maxLength(50),
                Select::make('reo_cage')
                    ->label('Reo cage')
                    ->options(self::REO_CAGES),
                TextInput::make('moulds_count')
                    ->label('Moulds #')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(65535),
                TextInput::make('pallets_count')
                    ->label('Pallets #')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(65535),
                Toggle::make('is_active')
                    ->label('Available for planning')
                    ->default(true),
            ]);
    }
}
