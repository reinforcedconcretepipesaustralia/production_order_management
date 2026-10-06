<?php

namespace App\Filament\Resources\SkuMasters\Pages;

use App\Filament\Resources\SkuMasters\SkuMasterResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSkuMaster extends CreateRecord
{
    protected static string $resource = SkuMasterResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['unit_weight_tonnes'] ??= 0;
        $data['wire_code'] ??= '';
        $data['wire_kg_per_pipe'] ??= 0;
        $data['standard_run_rate_per_hour'] ??= 0;

        return $data;
    }
}
