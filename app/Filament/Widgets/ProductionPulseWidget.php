<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\ProductionBoard;
use App\Models\User;
use App\Services\Operations\OperationsPulse;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ProductionPulseWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected static bool $isLazy = false;

    protected ?string $heading = 'Production';

    protected ?string $description = 'Melbourne today. Draft and cancelled runs are left out.';

    protected int|array|null $columns = 4;

    public static function canView(): bool
    {
        return Filament::auth()->check();
    }

    protected function getStats(): array
    {
        $pulse = $this->pulse();
        $board = ProductionBoard::getUrl();

        return [
            Stat::make('Planned pipe', number_format($pulse['planned_quantity']))
                ->description($pulse['runs'].' runs on the board')
                ->url($board),
            Stat::make('Made today', number_format($pulse['good_quantity']))
                ->description(number_format($pulse['dumped_quantity']).' dumped')
                ->color($pulse['good_quantity'] > 0 ? 'success' : 'gray')
                ->url($board),
            Stat::make('Runs', number_format($pulse['runs']))
                ->description('Scheduled, in progress, or completed')
                ->url($board),
            Stat::make('Dumped', number_format($pulse['dumped_quantity']))
                ->description('Recorded against today\'s actuals')
                ->color($pulse['dumped_quantity'] > 0 ? 'danger' : 'gray')
                ->url($board),
        ];
    }

    /**
     * @return array<string, int|float>
     */
    private function pulse(): array
    {
        $user = Filament::auth()->user();
        $service = app(OperationsPulse::class);
        $depotIds = $user instanceof User ? $service->depotIdsFor($user) : [];

        return $service->snapshot($depotIds, CarbonImmutable::now('Australia/Melbourne'));
    }
}
