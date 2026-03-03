<?php

namespace App\Providers\Filament;

use App\Domain\CRM\Models\Company;
use App\Http\Middleware\SetLocale;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use App\Filament\Auth\EditProfile;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class SupplierPortalPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('supplier-portal')
            ->path('supplier')
            ->login()
            ->passwordReset()
            ->profile(EditProfile::class)
            ->tenant(Company::class)
            ->tenantRoutePrefix('')
            ->sidebarCollapsibleOnDesktop()
            ->brandName('Impex Supplier Portal')
            ->databaseNotifications()
            ->viteTheme('resources/css/filament/supplier-portal/theme.css')
            ->colors([
                'primary' => Color::Amber,
                'danger' => Color::Red,
                'gray' => Color::Zinc,
                'info' => Color::Blue,
                'success' => Color::Green,
                'warning' => Color::Orange,
            ])
            ->navigationGroups([
                __('navigation.groups.operations'),
                __('navigation.groups.finance'),
            ])
            ->discoverResources(in: app_path('Filament/SupplierPortal/Resources'), for: 'App\\Filament\\SupplierPortal\\Resources')
            ->discoverPages(in: app_path('Filament/SupplierPortal/Pages'), for: 'App\\Filament\\SupplierPortal\\Pages')
            ->discoverWidgets(in: app_path('Filament/SupplierPortal/Widgets'), for: 'App\\Filament\\SupplierPortal\\Widgets')
            ->widgets([])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                SetLocale::class,
            ]);
    }
}
