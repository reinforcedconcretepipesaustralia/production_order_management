<?php

namespace App\Filament\Resources\SchedulingRules\Pages;

use App\Filament\Resources\SchedulingRules\SchedulingRuleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSchedulingRules extends ListRecords
{
    protected static string $resource = SchedulingRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }

    public function getSubheading(): ?string
    {
        return 'The automatic planner reads these before it lays a pipe. Change a value, then generate the next plan.';
    }
}
