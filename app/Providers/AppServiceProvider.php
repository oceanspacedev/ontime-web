<?php

namespace App\Providers;

use App\Enums\SystemRole;
use App\Services\Core\CoreSsoProvider;
use App\Services\Face\BasicFaceVerifier;
use App\Services\Face\CompreFaceVerifier;
use App\Services\Face\FaceVerifier;
use App\Support\IdleApiTokens;
use App\Support\ObservabilityAccess;
use App\Support\StorageUrl;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
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

        foreach (config('filesystems.disks') as $name => $disk) {
            if (($disk['driver'] ?? null) === 'local' && ($disk['serve'] ?? false)) {
                StorageUrl::serveInline(Storage::disk($name));
            }
        }

        Gate::before(function ($user, string $ability) {
            return $user->hasRole('Superadmin') ? true : null;
        });

        // API tokens of deactivated accounts (for example after an approved resignation) stop working,
        // and so do tokens left unused for longer than sanctum.idle_expiration.
        Sanctum::authenticateAccessTokensUsing(
            fn (PersonalAccessToken $token, bool $isValid): bool => $isValid
                && $token->tokenable?->is_active !== false
                && ! IdleApiTokens::isIdle($token),
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
        $this->limitPasswordLogin();
    }

    /**
     * Password guessing on the API login: 5 tries a minute per email from one address, 20 a minute
     * per address overall.
     */
    private function limitPasswordLogin(): void
    {
        $tooMany = fn (Request $request, array $headers) => response()->json(
            ['message' => 'Terlalu banyak percobaan masuk. Silakan coba lagi sebentar lagi.', 'retry_after' => (int) ($headers['Retry-After'] ?? 60)],
            429,
            $headers,
        );

        RateLimiter::for('api-login', fn (Request $request): array => [
            Limit::perMinute(5)->by('api-login:'.mb_strtolower(trim((string) $request->input('email'))).'|'.$request->ip())->response($tooMany),
            Limit::perMinute(20)->by('api-login-ip:'.$request->ip())->response($tooMany),
        ]);
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
