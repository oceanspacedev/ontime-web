<?php

namespace App\Notifications\Channels;

use App\Models\DeviceToken;
use App\Notifications\Messages\ExpoPushMessage;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Sends a notification to every Expo push token of the notifiable. Tokens Expo reports as
 * DeviceNotRegistered are deleted so they are not tried again.
 */
class ExpoPushChannel
{
    /** Expo accepts at most this many messages in one request. */
    public const MAX_PER_REQUEST = 100;

    public function send(object $notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toExpoPush')) {
            return;
        }

        $tokens = collect($notifiable->routeNotificationFor('expo', $notification) ?? [])
            ->filter(fn (mixed $token): bool => is_string($token) && $token !== '')
            ->unique()
            ->values();

        if ($tokens->isEmpty()) {
            return;
        }

        /** @var ExpoPushMessage $message */
        $message = $notification->toExpoPush($notifiable);

        foreach ($tokens->chunk(self::MAX_PER_REQUEST) as $chunk) {
            $chunkTokens = $chunk->values()->all();

            $response = $this->client()
                ->post(config('services.expo.push_url'), $message->toArray($chunkTokens))
                ->throw();

            $this->handleTickets($chunkTokens, $response->json('data') ?? []);
        }
    }

    protected function client(): PendingRequest
    {
        $request = Http::acceptJson()
            ->withHeaders(['Accept-Encoding' => 'gzip, deflate'])
            ->connectTimeout((float) config('services.expo.connect_timeout', 5))
            ->timeout((float) config('services.expo.timeout', 15));

        $accessToken = config('services.expo.access_token');

        return filled($accessToken) ? $request->withToken($accessToken) : $request;
    }

    /**
     * One ticket comes back per token, in the order sent. Only the error that says the token is
     * dead is acted on; everything else is logged for the Horizon dashboard.
     *
     * @param  list<string>  $tokens
     * @param  list<array<string, mixed>>  $tickets
     */
    protected function handleTickets(array $tokens, array $tickets): void
    {
        foreach ($tickets as $index => $ticket) {
            if (($ticket['status'] ?? 'ok') === 'ok') {
                continue;
            }

            $token = $tokens[$index] ?? null;
            $error = $ticket['details']['error'] ?? null;

            if ($token !== null && $error === 'DeviceNotRegistered') {
                DeviceToken::query()->where('token', $token)->delete();

                continue;
            }

            Log::warning('Expo push ticket error', [
                'error' => $error,
                'message' => $ticket['message'] ?? null,
                'token' => $token !== null ? '…'.substr($token, -6) : null,
            ]);
        }
    }
}
