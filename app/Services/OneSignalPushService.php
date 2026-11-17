<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\Channels\OneSignalChannel;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Server-side calls to OneSignal beside sending pushes ({@see OneSignalChannel}).
 *
 * The app links each install to the signed-in user (external id = user id) and only unlinks it itself on
 * sign-out. When the server revokes an account's app sessions, the phone is unlinked here as well, so a
 * lost or handed-back phone stops receiving that user's pushes before the app is ever opened again.
 */
final class OneSignalPushService
{
    /**
     * Delete the OneSignal user found by the account's external id, with its device subscriptions.
     *
     * A user OneSignal does not know (404) counts as done. Failures are logged, never thrown: revoking the
     * account's sessions must not fail because the push service is unreachable.
     */
    public function forgetUser(User $user): bool
    {
        if (! OneSignalChannel::isConfigured()) {
            return false;
        }

        try {
            $response = Http::acceptJson()
                ->withHeaders(['Authorization' => 'Key '.config('services.onesignal.rest_api_key')])
                ->connectTimeout((float) config('services.onesignal.connect_timeout', 5))
                ->timeout((float) config('services.onesignal.timeout', 15))
                ->delete($this->userUrl((string) $user->getKey()));
        } catch (Throwable $exception) {
            Log::warning('OneSignal user not unlinked', ['user' => $user->getKey(), 'error' => $exception->getMessage()]);

            return false;
        }

        if ($response->successful() || $response->notFound()) {
            return true;
        }

        Log::warning('OneSignal user not unlinked', ['user' => $user->getKey(), 'status' => $response->status(), 'body' => $response->json()]);

        return false;
    }

    /**
     * The user endpoint on the same host as the configured notifications endpoint.
     */
    private function userUrl(string $externalId): string
    {
        $notificationsUrl = (string) config('services.onesignal.api_url');
        $scheme = parse_url($notificationsUrl, PHP_URL_SCHEME) ?: 'https';
        $host = parse_url($notificationsUrl, PHP_URL_HOST) ?: 'api.onesignal.com';
        $appId = rawurlencode((string) config('services.onesignal.app_id'));

        return "{$scheme}://{$host}/apps/{$appId}/users/by/external_id/".rawurlencode($externalId);
    }
}
