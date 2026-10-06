<?php

namespace App\Filament\Resources\SkuMasters\Pages;

use App\Filament\Resources\SkuMasters\SkuMasterResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditSkuMaster extends EditRecord
{
    protected static string $resource = SkuMasterResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
