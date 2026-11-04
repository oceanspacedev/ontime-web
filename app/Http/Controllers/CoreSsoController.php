<?php

namespace App\Http\Controllers;

use App\Services\Core\CoreUserSynchronizer;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use Spatie\Permission\Models\Permission;

class CoreSsoController extends Controller
{
    /**
     * Redirect the user to the Core SSO authorization page.
     */
    public function redirect()
    {
        // SSO is optional: without CORE_CLIENT_ID / CORE_CLIENT_SECRET / CORE_REDIRECT_URI
        // send people back to the regular email and password login instead of a broken redirect.
        if (blank(config('services.core.client_id')) || blank(config('services.core.client_secret')) || blank(config('services.core.redirect'))) {
            return redirect()->to(Filament::getLoginUrl());
        }

        return Socialite::driver('core')->redirect();
    }

    /**
     * Obtain the user information from the Core SSO.
     */
    public function callback(CoreUserSynchronizer $userSynchronizer)
    {
        try {
            $ssoUser = Socialite::driver('core')->user();

            // Sync user details and auto-link employee
            $user = $userSynchronizer->sync($ssoUser->getRaw());

            // Sync permissions
            if (isset($ssoUser->user['permissions']) && is_array($ssoUser->user['permissions'])) {
                $validPermissions = [];
                foreach ($ssoUser->user['permissions'] as $permissionName) {
                    Permission::findOrCreate($permissionName, 'web');
                    $validPermissions[] = $permissionName;
                }
                $user->syncPermissions($validPermissions);
            }

            // Log the user in
            Auth::login($user);

            // Redirect to intended dashboard
            return redirect()->intended(config('filament.home_url', '/admin'));

        } catch (\Exception $e) {
            // Log or handle the exception appropriately
            return redirect('/')->withErrors(['error' => 'SSO Authentication failed: '.$e->getMessage()]);
        }
    }
}
