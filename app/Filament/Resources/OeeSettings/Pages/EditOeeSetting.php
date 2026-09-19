<?php

namespace App\Filament\Resources\OeeSettings\Pages;

use App\Filament\Resources\OeeSettings\OeeSettingResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditOeeSetting extends EditRecord
{
    protected static string $resource = OeeSettingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
