<?php

namespace App\Filament\Resources\NonProductionDays\Pages;

use App\Filament\Resources\NonProductionDays\NonProductionDayResource;
use Filament\Resources\Pages\CreateRecord;

class CreateNonProductionDay extends CreateRecord
{
    protected static string $resource = NonProductionDayResource::class;
}
