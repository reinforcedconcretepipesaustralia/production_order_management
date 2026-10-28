<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\LogisticsDeliverySchedule;
use App\Filament\Pages\LogisticsDispatchCalendar;
use App\Filament\Pages\LogisticsLoadBuilder;
use App\Models\User;
use App\Services\Operations\OperationsPulse;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class DispatchPulseWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 2;

    protected static bool $isLazy = false;

    protected ?string $heading = 'Despatch';

    protected ?string $description = 'Melbourne today, from the delivery schedule and loads already created.';

    protected int|array|null $columns = 4;

    public static function canView(): bool
    {
        return Filament::auth()->check();
    }

    protected function getStats(): array
    {
        $pulse = $this->pulse();

        return [
            Stat::make('Schedule lines', number_format($pulse['schedule_lines']))
                ->description($pulse['unconfirmed_lines'].' still to confirm')
                ->url(LogisticsDeliverySchedule::getUrl()),
            Stat::make('Confirmed', number_format($pulse['confirmed_tonnes'], 1).' t')
                ->description($pulse['confirmed_lines'].' lines confirmed')
                ->color($pulse['confirmed_lines'] > 0 ? 'success' : 'gray')
                ->url(LogisticsDeliverySchedule::getUrl()),
            Stat::make('On trucks', number_format($pulse['loaded_quantity']))
                ->description($pulse['loads'].' loads · '.number_format($pulse['load_tonnes'], 1).' / '.number_format($pulse['load_capacity_tonnes'], 1).' t')
                ->url(LogisticsLoadBuilder::getUrl()),
            Stat::make('Coming up', 'Calendar')
                ->description('Weeks of due, confirmed, and loaded pipe')
                ->url(LogisticsDispatchCalendar::getUrl()),
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
