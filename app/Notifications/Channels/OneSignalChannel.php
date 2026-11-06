<?php

namespace App\Notifications\Channels;

use App\Notifications\Messages\OneSignalMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Sends a push through OneSignal to the notifiable user. OneSignal keeps the devices: the app links
 * each install to the signed-in user, so nothing is stored here.
 */
class OneSignalChannel
{
    public static function isConfigured(): bool
    {
        return filled(config('services.onesignal.app_id')) && filled(config('services.onesignal.rest_api_key'));
    }

    public function send(object $notifiable, Notification $notification): void
    {
        if (! self::isConfigured() || ! method_exists($notification, 'toOneSignal')) {
            return;
        }

        $externalId = $notifiable->routeNotificationFor('onesignal', $notification) ?? $notifiable->getKey();

        if (blank($externalId)) {
            return;
        }

        /** @var OneSignalMessage $message */
        $message = $notification->toOneSignal($notifiable);

        $response = Http::acceptJson()
            ->withHeaders(['Authorization' => 'Key '.config('services.onesignal.rest_api_key')])
            ->connectTimeout((float) config('services.onesignal.connect_timeout', 5))
            ->timeout((float) config('services.onesignal.timeout', 15))
            ->post(config('services.onesignal.api_url'), $message->toArray((string) config('services.onesignal.app_id'), [(string) $externalId]))
            ->throw();

        // A user without a subscribed device is not a failure: OneSignal answers 200 with errors.
        if (filled($response->json('errors'))) {
            Log::info('OneSignal push not delivered', [
                'user' => $externalId,
                'notification' => $notification::class,
                'errors' => $response->json('errors'),
            ]);
        }
    }
}
