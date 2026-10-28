<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\HasOperationalFilters;
use App\Services\Logistics\DispatchHorizon;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;

class LogisticsDispatchCalendar extends Page
{
    use HasOperationalFilters;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-calendar';

    protected static string|\UnitEnum|null $navigationGroup = 'Logistics';

    protected static ?string $navigationLabel = 'Dispatch calendar';

    protected static ?string $title = 'Dispatch calendar';

    protected static ?int $navigationSort = 5;

    protected string $view = 'filament.pages.logistics-dispatch-calendar';

    public string $month = '';

    public string $selectedDate = '';

    public function mount(): void
    {
        $this->month = now()->startOfMonth()->toDateString();
        $this->selectedDate = now()->toDateString();
        $this->syncOperationalFilterSelection();
    }

    public static function canAccess(): bool
    {
        return static::canViewOperationalPage();
    }

    public function previousMonth(): void
    {
        $this->month = CarbonImmutable::parse($this->month)->subMonth()->startOfMonth()->toDateString();
    }

    public function nextMonth(): void
    {
        $this->month = CarbonImmutable::parse($this->month)->addMonth()->startOfMonth()->toDateString();
    }

    public function selectDate(string $date): void
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return;
        }

        $this->selectedDate = $date;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $filters = $this->operationalFilterOptions();
        $month = CarbonImmutable::parse($this->month)->startOfMonth();
        $gridStart = $month->startOfWeek(CarbonImmutable::MONDAY);
        $gridEnd = $month->endOfMonth()->endOfWeek(CarbonImmutable::SUNDAY);
        $depotIds = $this->depotId !== null
            ? [$this->depotId]
            : $filters['plants']->modelKeys();

        $days = app(DispatchHorizon::class)->between($gridStart, $gridEnd, $depotIds);
        $selected = $days->get($this->selectedDate);

        $scheduleUrl = LogisticsDeliverySchedule::getUrl().'?'.http_build_query(array_filter([
            'date' => $this->selectedDate,
            'depot' => $this->depotId,
        ]));

        $cells = [];
        for ($cursor = $gridStart; $cursor->lte($gridEnd); $cursor = $cursor->addDay()) {
            $date = $cursor->toDateString();
            $cells[] = [
                'date' => $date,
                'day' => $cursor->day,
                'inMonth' => $cursor->month === $month->month,
                'summary' => $days->get($date),
            ];
        }

        return [
            'plants' => $filters['plants'],
            'shifts' => $filters['shifts'],
            'machines' => $filters['machines'],
            'monthLabel' => $month->format('F Y'),
            'cells' => $cells,
            'selected' => $selected,
            'selectedDate' => $this->selectedDate,
            'scheduleUrl' => $scheduleUrl,
        ];
    }
}
