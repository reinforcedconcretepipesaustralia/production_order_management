<?php

namespace App\Providers\Filament;

use BezhanSalleh\FilamentShield\FilamentShieldPlugin;
use Filament\Enums\ThemeMode;
use Filament\Enums\UserMenuPosition;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\View\PanelsRenderHook;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
use Illuminate\Contracts\View\View;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->brandName('RCPA')
            ->brandLogo(asset('images/brand/rcpa-logo.svg'))
            ->darkModeBrandLogo(asset('images/brand/rcpa-logo-reversed.svg'))
            // The artwork is trimmed to the mark, so this height is the mark itself, with no padding.
            ->brandLogoHeight(fn (): string => request()->routeIs('filament.admin.auth.*')
                ? '3.25rem'
                : '2.5rem')
            ->favicon(asset('images/brand/rcpa-logo.svg'))
            ->font('Poppins')
            ->defaultThemeMode(ThemeMode::Light)
            ->colors([
                'primary' => '#002F67',
                'warning' => '#FFD400',
                'danger' => '#B3261E',
                'success' => '#1E6E42',
                'info' => '#2A5A96',
            ])
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->userMenu(position: UserMenuPosition::Sidebar)
            // No topbar, so the navy sidebar runs the full height and carries the logo.
            ->topbar(false)
            ->sidebarCollapsibleOnDesktop()
            ->navigationGroups([
                'Operations',
                'Plant Configuration',
                'Data',
                'Access',
            ])
            ->renderHook(
                PanelsRenderHook::USER_MENU_BEFORE,
                fn (): View => view('filament.sidebar-toggle'),
            )
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
                FilamentInfoWidget::class,
            ])
            ->plugins([
                FilamentShieldPlugin::make()
                    ->navigationGroup('Access')
                    ->navigationSort(2),
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
