<?php

namespace App\Providers;

use App\Enums\SystemRole;
use App\Services\Core\CoreSsoProvider;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use Laravel\Socialite\Facades\Socialite;
use Spatie\Permission\Models\Role;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Model::preventLazyLoading(! app()->isProduction());

        Gate::before(function ($user, string $ability) {
            return $user->hasRole('Superadmin') ? true : null;
        });

        // API tokens of deactivated accounts (for example after an approved resignation) stop working.
        Sanctum::authenticateAccessTokensUsing(
            fn (PersonalAccessToken $token, bool $isValid): bool => $isValid && $token->tokenable?->is_active !== false,
        );

        if (class_exists(Socialite::class)) {
            Socialite::extend('core', function ($app) {
                $config = $app['config']['services.core'] ?? [];

                return Socialite::buildProvider(CoreSsoProvider::class, $config);
            });
        }

        $this->protectSystemRoles();
        $this->explainBlockedDeletions();
    }

    /**
     * The application checks these roles by name, so they must not be renamed or deleted from
     * the Roles page. Gate::before lets Superadmin past any RolePolicy, hence the model events.
     */
    private function protectSystemRoles(): void
    {
        Role::deleting(fn (Role $role): bool => ! SystemRole::isSystemRole($role->name));

        Role::updating(function (Role $role): void {
            if ($role->isDirty('name') && SystemRole::isSystemRole((string) $role->getOriginal('name'))) {
                throw ValidationException::withMessages([
                    'data.name' => 'Nama role sistem tidak boleh diubah karena dipakai langsung oleh aplikasi.',
                ]);
            }
        });
    }

    /**
     * Models using PreventsDeletionWhileInUse cancel their own delete. Show the reason
     * instead of silently doing nothing.
     */
    private function explainBlockedDeletions(): void
    {
        DeleteAction::configureUsing(function (DeleteAction $action): void {
            $action
                ->failureNotificationTitle('Data tidak dapat dihapus')
                ->failureNotificationBody(fn (Model $record): ?string => match (true) {
                    method_exists($record, 'deletionBlockedReason') => $record->deletionBlockedReason(),
                    $record instanceof Role && SystemRole::isSystemRole($record->name) => 'Role sistem dipakai langsung oleh aplikasi dan tidak boleh dihapus.',
                    default => null,
                });
        });

        DeleteBulkAction::configureUsing(function (DeleteBulkAction $action): void {
            $action->missingBulkProcessingFailureNotificationMessage(
                fn (int $failureCount): string => "{$failureCount} data tidak dihapus karena masih dipakai oleh data lain atau dilindungi sistem.",
            );
        });
    }
}
