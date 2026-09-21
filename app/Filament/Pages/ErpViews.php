<?php

namespace App\Filament\Pages;

use App\Enums\Capability;
use App\Models\User;
use App\Services\Rcpa\RcpaViewReadService;
use App\Support\Rcpa\RcpaViewCatalog;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class ErpViews extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-circle-stack';

    protected static string|\UnitEnum|null $navigationGroup = 'Data';

    protected static ?string $navigationLabel = 'ERP Views';

    protected static ?int $navigationSort = 40;

    protected string $view = 'filament.pages.erp-views';

    public string $selectedTable = '';

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof User && $user->hasCapability(Capability::ViewMasterData);
    }

    /**
     * Drop the cached handshake failure so a fresh VPN session is picked up
     * immediately rather than after the failure cache expires.
     */
    public function retryConnection(): void
    {
        (new RcpaViewReadService(new RcpaViewCatalog))->forgetConnectionFailure();

        Notification::make()
            ->title('Rechecking the RCPA SQL connection')
            ->success()
            ->send();
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $reader = new RcpaViewReadService(new RcpaViewCatalog);
        $views = $reader->probeMappedViews();
        $selected = collect($views)->firstWhere('table', $this->selectedTable);
        $setupRequired = ! collect($views)->contains(
            fn (array $view): bool => $view['status'] === 'readable',
        );

        return [
            'views' => $views,
            'preview' => ($selected['status'] ?? null) === 'readable'
                ? $reader->previewMappedView($this->selectedTable)
                : ['columns' => [], 'rows' => []],
            'setupRequired' => $setupRequired,
            'setupReason' => $setupRequired ? ($views[0]['reason'] ?? null) : null,
        ];
    }
}
