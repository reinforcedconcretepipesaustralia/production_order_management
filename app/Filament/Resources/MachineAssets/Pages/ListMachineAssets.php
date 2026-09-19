<?php

namespace App\Filament\Resources\MachineAssets\Pages;

use App\Filament\Resources\MachineAssets\MachineAssetResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListMachineAssets extends ListRecords
{
    protected static string $resource = MachineAssetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
