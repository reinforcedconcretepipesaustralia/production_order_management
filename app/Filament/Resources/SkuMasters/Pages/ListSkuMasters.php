<?php

namespace App\Filament\Resources\SkuMasters\Pages;

use App\Filament\Resources\SkuMasters\SkuMasterResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSkuMasters extends ListRecords
{
    protected static string $resource = SkuMasterResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
