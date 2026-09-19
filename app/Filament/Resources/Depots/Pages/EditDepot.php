<?php

namespace App\Filament\Resources\Depots\Pages;

use App\Filament\Resources\Depots\DepotResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditDepot extends EditRecord
{
    protected static string $resource = DepotResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
