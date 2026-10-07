<?php

namespace App\Filament\Resources\SchedulingRules\Pages;

use App\Filament\Resources\SchedulingRules\SchedulingRuleResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditSchedulingRule extends EditRecord
{
    protected static string $resource = SchedulingRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
