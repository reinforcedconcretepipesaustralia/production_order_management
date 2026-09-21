<?php

namespace App\Filament\Pages;

use App\Enums\Capability;
use App\Filament\Concerns\HasOperationalFilters;
use Filament\Actions\Action;
use Filament\Pages\Page;

class AlternateProductionBoard extends Page
{
    use HasOperationalFilters;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-calendar';

    protected static string|\UnitEnum|null $navigationGroup = 'Operations';

    protected static ?string $navigationLabel = 'Production Board (Guava)';

    protected static ?int $navigationSort = 11;

    protected string $view = 'filament.pages.alternate-production-board';

    public static function canAccess(): bool
    {
        return static::canViewOperationalPage([
            Capability::ViewSchedules,
            Capability::ViewDepotOperations,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return $this->operationalFilterOptions();
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('currentProductionBoard')
                ->label('Open current board')
                ->icon('heroicon-o-arrow-top-right-on-square')
                ->url(ProductionBoard::getUrl()),
        ];
    }
}
