<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class WhatsAppNotificationService
{
    /**
     * Normalize phone number to international 628... format.
     */
    public static function normalizePhoneNumber(?string $phone): ?string
    {
        if (empty($phone)) {
            return null;
        }

        $digits = preg_replace('/\D/', '', $phone);
        if (empty($digits)) {
            return null;
        }

        if (str_starts_with($digits, '0')) {
            return '62'.substr($digits, 1);
        }

        if (str_starts_with($digits, '8')) {
            return '62'.$digits;
        }

        return $digits;
    }

    /**
     * Core method to send generic WhatsApp message via WAG Gateway.
     *
     * @return array{success: bool, api_sent: bool, api_error: string|null, message: string, phone: string|null, wa_url: string|null}
     */
    public static function sendMessage(?string $phone, string $message, string $purpose = 'notification', ?int $expiresInMinutes = null): array
    {
        $normalizedPhone = static::normalizePhoneNumber($phone);
        $waUrl = $normalizedPhone ? 'https://wa.me/'.$normalizedPhone.'?text='.rawurlencode($message) : null;

        // The text is not logged: it can hold one-time codes or a new account's password.
        Log::info("WhatsApp message [{$purpose}] queued for ".($normalizedPhone ? '…'.substr($normalizedPhone, -4) : 'unknown number'));

        $gatewayUrl = config('services.whatsapp_gateway.url');
        $token = config('services.whatsapp_gateway.token');

        $apiSuccess = false;
        $apiError = null;

        if (filled($gatewayUrl) && filled($token) && $normalizedPhone) {
            try {
                $payload = [
                    'recipient' => [
                        'type' => 'phone',
                        'value' => $normalizedPhone,
                    ],
                    'message' => [
                        'type' => 'text',
                        'text' => $message,
                    ],
                    'purpose' => $purpose,
                    'mode' => 'sync',
                    'route_key' => 'default',
                    'client_reference' => 'ontime',
                ];

                if ($expiresInMinutes !== null && $expiresInMinutes > 0) {
                    $payload['expires_at'] = now()->addMinutes($expiresInMinutes)->toIso8601String();
                }

                $response = Http::withHeaders([
                    'Authorization' => 'Bearer '.preg_replace('/^Bearer\s+/i', '', (string) $token),
                    'Accept' => 'application/json',
                    'Idempotency-Key' => 'ontime-'.Str::uuid(),
                ])
                    ->connectTimeout((float) config('services.whatsapp_gateway.connect_timeout', 5))
                    ->timeout((float) config('services.whatsapp_gateway.timeout', 15))
                    ->post(static::messagesEndpoint((string) $gatewayUrl), $payload);

                if ($response->successful()) {
                    $apiSuccess = true;
                    Log::info("WhatsApp Gateway (WAG) message [{$purpose}] sent.");
                } else {
                    $resJson = $response->json();
                    $errorMessage = $resJson['message'] ?? mb_substr($response->body(), 0, 500);
                    $apiError = "WAG API ({$response->status()}): {$errorMessage}";
                    Log::warning("WhatsApp Gateway (WAG) API error [{$purpose}]: {$apiError}");
                }
            } catch (\Throwable $e) {
                $apiError = $e->getMessage();
                Log::warning("WhatsApp Gateway (WAG) API failed [{$purpose}]: ".$apiError);
            }
        }

        return [
            'success' => true,
            'api_sent' => $apiSuccess,
            'api_error' => $apiError,
            'message' => $message,
            'phone' => $normalizedPhone,
            'wa_url' => $waUrl,
        ];
    }

    /**
     * WAG_URL is the WagHub base URL, as in gais-web and helpdesk-web; the messages path is added here.
     * A URL that already has a path (older OnTime configs held the full endpoint) is used as it is.
     */
    public static function messagesEndpoint(string $gatewayUrl): string
    {
        $gatewayUrl = rtrim(trim($gatewayUrl), '/');
        $path = (string) parse_url($gatewayUrl, PHP_URL_PATH);

        return $path === '' ? $gatewayUrl.'/api/v1/messages' : $gatewayUrl;
    }

    /**
     * The welcome message for a new account: where to get the app, then how to sign in.
     */
    public static function accountCredentialsMessage(string $name, ?string $phone, string $email, string $password): string
    {
        $stores = collect([
            'Android' => config('services.mobile_app.play_store_url'),
            'iPhone' => config('services.mobile_app.app_store_url'),
        ])->filter()->map(fn (string $url, string $platform): string => "{$platform}: {$url}");

        $lines = ["Halo {$name}, akun OnTime Anda sudah aktif.", ''];

        if ($stores->isNotEmpty()) {
            array_push($lines, '*1. Pasang aplikasi OnTime*', ...$stores->values()->all());
            $lines[] = '';
        }

        array_push(
            $lines,
            $stores->isNotEmpty() ? '*2. Masuk*' : '*Masuk ke aplikasi OnTime*',
            "Email: {$email}",
            "Kata sandi: {$password}",
        );

        if (static::normalizePhoneNumber($phone)) {
            $lines[] = 'Atau tekan tombol *WhatsApp* di halaman masuk dan pakai nomor ini.';
        }

        array_push($lines, '', 'Jangan bagikan kata sandi ini ke siapa pun.');

        return implode("\n", $lines);
    }

    /**
     * Send a new account's welcome message.
     *
     * @return array{success: bool, api_sent: bool, api_error: string|null, message: string, phone: string|null, wa_url: string|null}
     */
    public static function sendAccountCredentials(string $name, ?string $phone, string $email, string $password): array
    {
        return static::sendMessage($phone, static::accountCredentialsMessage($name, $phone, $email, $password), 'notification');
    }

    /**
     * Format and send WhatsApp OTP verification code.
     * Uses purpose = 'otp'.
     *
     * @return array{success: bool, api_sent: bool, api_error: string|null, message: string, phone: string|null, wa_url: string|null}
     */
    public static function sendOtpCode(?string $phone, string $otpCode, int $expiresInMinutes = 5): array
    {
        $message = "Kode verifikasi OnTime Anda: *{$otpCode}*\n"
            ."Berlaku {$expiresInMinutes} menit dan hanya untuk satu proses login. "
            .'Jangan bagikan kode ini kepada siapa pun.';

        return static::sendMessage($phone, $message, 'otp', $expiresInMinutes);
    }
}
