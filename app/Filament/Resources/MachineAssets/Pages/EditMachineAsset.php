<?php

namespace App\Filament\Resources\MachineAssets\Pages;

use App\Filament\Resources\MachineAssets\MachineAssetResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditMachineAsset extends EditRecord
{
    protected static string $resource = MachineAssetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
