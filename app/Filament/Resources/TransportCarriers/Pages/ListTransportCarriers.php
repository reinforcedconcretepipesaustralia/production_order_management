<?php

namespace App\Filament\Resources\TransportCarriers\Pages;

use App\Filament\Resources\TransportCarriers\TransportCarrierResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTransportCarriers extends ListRecords
{
    protected static string $resource = TransportCarrierResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
