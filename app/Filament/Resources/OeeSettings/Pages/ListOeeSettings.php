<?php

namespace App\Filament\Resources\OeeSettings\Pages;

use App\Filament\Resources\OeeSettings\OeeSettingResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListOeeSettings extends ListRecords
{
    protected static string $resource = OeeSettingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
