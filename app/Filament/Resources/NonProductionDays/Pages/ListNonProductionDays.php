<?php

namespace App\Filament\Resources\NonProductionDays\Pages;

use App\Filament\Resources\NonProductionDays\NonProductionDayResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListNonProductionDays extends ListRecords
{
    protected static string $resource = NonProductionDayResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
