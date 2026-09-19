<?php

namespace App\Filament\Resources\Depots\Pages;

use App\Filament\Resources\Depots\DepotResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDepots extends ListRecords
{
    protected static string $resource = DepotResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
