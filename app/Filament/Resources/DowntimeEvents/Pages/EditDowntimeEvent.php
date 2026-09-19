<?php

namespace App\Filament\Resources\DowntimeEvents\Pages;

use App\Filament\Resources\DowntimeEvents\DowntimeEventResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditDowntimeEvent extends EditRecord
{
    protected static string $resource = DowntimeEventResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
