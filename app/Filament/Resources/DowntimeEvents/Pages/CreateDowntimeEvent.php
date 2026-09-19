<?php

namespace App\Filament\Resources\DowntimeEvents\Pages;

use App\Filament\Resources\DowntimeEvents\DowntimeEventResource;
use Filament\Resources\Pages\CreateRecord;

class CreateDowntimeEvent extends CreateRecord
{
    protected static string $resource = DowntimeEventResource::class;
}
