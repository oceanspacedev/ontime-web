<?php

namespace App\Providers;

use App\Enums\SystemRole;
use App\Services\Core\CoreSsoProvider;
use App\Services\Face\BasicFaceVerifier;
use App\Services\Face\CompreFaceVerifier;
use App\Services\Face\FaceVerifier;
use App\Support\ObservabilityAccess;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
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
        $this->app->bind(FaceVerifier::class, fn (Application $app): FaceVerifier => match (config('services.face.driver')) {
            'compreface' => $app->make(CompreFaceVerifier::class),
            default => $app->make(BasicFaceVerifier::class),
        });
    }

    public function boot(): void
    {
        Model::preventLazyLoading(! app()->isProduction());

        ObservabilityAccess::register();

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
        $this->limitOtpLogin();
    }

    /**
     * Per-address ceilings for the OTP login endpoints. The per-number and per-email limits live in
     * the OTP services themselves, the same ones the web login pages use.
     */
    private function limitOtpLogin(): void
    {
        RateLimiter::for('api-otp-request', fn (Request $request): Limit => Limit::perMinute(10)->by('api-otp-request:'.$request->ip()));
        RateLimiter::for('api-otp-verify', fn (Request $request): Limit => Limit::perMinute(20)->by('api-otp-verify:'.$request->ip()));
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
