<?php

namespace App\Filament\Resources\NonProductionDays\Pages;

use App\Filament\Resources\NonProductionDays\NonProductionDayResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditNonProductionDay extends EditRecord
{
    protected static string $resource = NonProductionDayResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
