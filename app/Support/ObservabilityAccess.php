<?php

namespace App\Support;

use App\Models\User;
use App\Services\DataAccessService;
use Filament\Navigation\NavigationItem;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;

/**
 * Who may open Horizon and the Log Viewer, the same setup as dnd-web, gais-web and helpdesk-web.
 */
final class ObservabilityAccess
{
    /**
     * Horizon and the Log Viewer show queue jobs and logs of every company, so only Superadmin and an
     * Admin with data access "Semua" may open them.
     */
    public static function allowed(?Authenticatable $user): bool
    {
        return $user instanceof User && DataAccessService::hasGlobalAccess($user);
    }

    public static function register(): void
    {
        $gate = fn ($user = null): bool => self::allowed($user instanceof Authenticatable ? $user : null);

        Gate::define('viewHorizon', $gate);
        Gate::define('viewLogViewer', $gate);
        Gate::define('deleteLogFile', $gate);
        Gate::define('deleteLogFolder', $gate);
        Gate::define('downloadLogFile', $gate);
        Gate::define('downloadLogFolder', $gate);
    }

    /**
     * @return list<NavigationItem>
     */
    public static function filamentNavigationItems(): array
    {
        return [
            NavigationItem::make('Horizon')
                ->url(url('/'.trim((string) config('horizon.path', 'horizon'), '/')), shouldOpenInNewTab: true)
                ->icon('heroicon-o-queue-list')
                ->group('Observability')
                ->sort(100)
                ->visible(fn (): bool => self::allowed(auth()->user())),
            NavigationItem::make('Log Viewer')
                ->url(url('/'.trim((string) config('log-viewer.route_path', 'log-viewer'), '/')), shouldOpenInNewTab: true)
                ->icon('heroicon-o-document-magnifying-glass')
                ->group('Observability')
                ->sort(101)
                ->visible(fn (): bool => self::allowed(auth()->user())),
        ];
    }
}
