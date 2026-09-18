<?php

namespace App\Filament\Pages;

use App\Enums\Capability;
use App\Models\User;
use App\Services\Rcpa\RcpaViewReadService;
use App\Support\Rcpa\RcpaViewCatalog;
use Filament\Facades\Filament;
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
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $reader = new RcpaViewReadService(new RcpaViewCatalog);
        $views = $reader->probeMappedViews();
        $selected = collect($views)->firstWhere('table', $this->selectedTable);

        return [
            'views' => $views,
            'preview' => ($selected['status'] ?? null) === 'readable'
                ? $reader->previewMappedView($this->selectedTable)
                : ['columns' => [], 'rows' => []],
        ];
    }
}
