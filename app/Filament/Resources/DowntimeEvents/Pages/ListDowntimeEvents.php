<?php

namespace App\Filament\Resources\DowntimeEvents\Pages;

use App\Filament\Resources\DowntimeEvents\DowntimeEventResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDowntimeEvents extends ListRecords
{
    protected static string $resource = DowntimeEventResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
