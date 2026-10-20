<?php

namespace App\Filament\Resources\TransportCarriers\Pages;

use App\Filament\Resources\TransportCarriers\TransportCarrierResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditTransportCarrier extends EditRecord
{
    protected static string $resource = TransportCarrierResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
