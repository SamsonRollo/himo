<?php

namespace App\Providers\Filament;

use App\Filament\Auth\Pages\Login;
use App\Filament\Pages\ServiceAccomplishmentReport;
use App\Filament\Resources\ServiceRequests\Pages\ListServiceRequests;
use App\Filament\Widgets\RecentlyCompletedWidget;
use App\Filament\Widgets\RecentRequestsWidget;
use App\Filament\Widgets\StaffWorkloadWidget;
use BezhanSalleh\FilamentShield\FilamentShieldPlugin;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Pages\Dashboard;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Resources\Pages\Page as ResourcePage;
use Filament\Support\Assets\Js;
use Filament\Support\Colors\Color;
use Filament\Support\Contracts\ScalableIcon;
use Filament\Support\Enums\IconSize;
use Filament\Tables\View\TablesRenderHook;
use Filament\View\PanelsRenderHook;
use Filament\Widgets\View\WidgetsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\HtmlString;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login(Login::class)
            ->brandName('HIMO')
            ->brandLogo(fn () => view('filament.partials.brand-logo'))
            ->brandLogoHeight('calc(var(--topbar-height) - 10px)')
            ->colors([
                // UP Maroon: primary actions, active navigation, emphasis.
                'primary' => Color::hex('#7B1113'),
                // Forest Green: complementary/success-adjacent states.
                'success' => Color::hex('#014421'),
                // Gold, approximating Pantone 1235C: used sparingly for
                // attention indicators, never as a primary or status color.
                'gold' => Color::hex('#FFC72C'),
            ])
            // No calendar package in this repo (saade/filament-fullcalendar
            // doesn't support Filament v5 yet); FullCalendar.js loaded from
            // a CDN instead, consumed by ServiceCalendar's Alpine component.
            ->assets([
                Js::make('fullcalendar', 'https://cdn.jsdelivr.net/npm/fullcalendar@6/index.global.min.js'),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            // No AccountWidget/FilamentInfoWidget: those render the default
            // "Welcome to <app>" card and Filament's own branding links,
            // which the panel's role-aware widgets replace.
            ->widgets([])
            ->navigationGroups([
                NavigationGroup::make('Service Requests'),
                NavigationGroup::make('Service Management'),
                NavigationGroup::make('Users & Access'),
                NavigationGroup::make('Reports & Monitoring'),
                NavigationGroup::make('System Administration'),
            ])
            ->sidebarCollapsibleOnDesktop()
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn () => view('filament.partials.topbar-brand-styles'),
            )
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn (): HtmlString => $this->currentModuleFavicon(),
            )
            ->renderHook(
                PanelsRenderHook::TOPBAR_START,
                fn () => view('filament.partials.topbar-brand-logo'),
            )
            ->renderHook(
                PanelsRenderHook::USER_MENU_BEFORE,
                fn () => view('filament.partials.user-identity'),
            )
            // Priority sort shortcut, between the search field and the
            // filters trigger, on the two tables built from
            // ServiceRequestResource::requestColumns(): the request list
            // and the accomplishment report.
            ->renderHook(
                TablesRenderHook::TOOLBAR_SEARCH_AFTER,
                fn () => view('filament.partials.priority-sort-toggle'),
                scopes: [ListServiceRequests::class, ServiceAccomplishmentReport::class],
            )
            ->renderHook(
                WidgetsRenderHook::TABLE_WIDGET_START,
                fn () => '<span class="himo-dashboard-table-height-marker" hidden></span>',
                scopes: [StaffWorkloadWidget::class, RecentRequestsWidget::class, RecentlyCompletedWidget::class],
            )
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
            ->plugins([
                FilamentShieldPlugin::make(),
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }

    /**
     * Favicon matching the navigation icon of the module being viewed (a
     * resource page uses its resource's icon), recoloured UP Maroon since
     * currentColor has no meaning in a browser tab. Pages outside any
     * module, such as login, fall back to the HIMO logo.
     */
    private function currentModuleFavicon(): HtmlString
    {
        $page = Route::current()?->getControllerClass();

        $icon = match (true) {
            is_a($page, ResourcePage::class, true) => $page::getResource()::getNavigationIcon(),
            is_a($page, Page::class, true) => $page::getNavigationIcon(),
            default => null,
        };

        $iconName = $icon instanceof ScalableIcon ? $icon->getIconForSize(IconSize::Large) : $icon;

        if (! is_string($iconName)) {
            return new HtmlString('<link rel="icon" type="image/png" href="'.e(asset('images/branding/himo-logo.png')).'">');
        }

        $svg = str_replace('currentColor', '#7B1113', svg($iconName)->toHtml());

        return new HtmlString('<link rel="icon" type="image/svg+xml" href="data:image/svg+xml,'.rawurlencode($svg).'">');
    }
}
