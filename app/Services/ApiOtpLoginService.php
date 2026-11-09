<?php

namespace App\Services;

use App\Models\EmailOtp;
use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Throwable;

/**
 * One-time-code login for the REST API, on top of the WhatsApp and email flows of the web login pages.
 *
 * A challenge id stands in for the browser session the web pages use: it records which channel and
 * which number or address a code was sent to, so the client only has to send the id and the code
 * back. Like the web pages, a request never reveals whether the number or address has an account.
 *
 * The email link login works the same way: the email carries only a link that opens the app, and the
 * app sends the link's token together with the challenge id it kept. A token on its own, say from an
 * email opened on another device, logs nobody in.
 */
class ApiOtpLoginService
{
    public const CHANNEL_WHATSAPP = 'whatsapp';

    public const CHANNEL_EMAIL = 'email';

    public const CHANNELS = [self::CHANNEL_WHATSAPP, self::CHANNEL_EMAIL];

    /** Challenge channel of the email link login; not one a client can pick for a code. */
    private const CHANNEL_EMAIL_LINK = 'email-link';

    private const OTP_PURPOSE = 'login';

    private const OTP_SUBJECT = 'api-login';

    public function __construct(
        private WhatsAppOtpService $whatsAppOtpService,
        private EmailOtpService $emailOtpService,
    ) {}

    public static function isChannelConfigured(string $channel): bool
    {
        return match ($channel) {
            self::CHANNEL_WHATSAPP => WhatsAppOtpService::isConfigured(),
            self::CHANNEL_EMAIL => EmailOtpService::isConfigured(),
            default => false,
        };
    }

    /**
     * Send a code and open a challenge. Statuses: otp_sent, invalid_destination, rate_limited, busy,
     * delivery_failed.
     *
     * @return array{ok: bool, status: string, challenge_id: string|null, expires_in: int, retry_after: int}
     */
    public function request(string $channel, string $destination, string $ip): array
    {
        return match ($channel) {
            self::CHANNEL_WHATSAPP => $this->requestWhatsApp($destination, $ip),
            self::CHANNEL_EMAIL => $this->requestEmail($destination, $ip),
            default => $this->failure('invalid_destination'),
        };
    }

    /**
     * Email a link that opens the app and open a challenge for it. Same statuses as request().
     *
     * @return array{ok: bool, status: string, challenge_id: string|null, expires_in: int, retry_after: int}
     */
    public function requestMagicLink(string $destination, string $ip): array
    {
        return $this->requestEmail($destination, $ip, magicLinkOnly: true);
    }

    /**
     * Check the token from the email link against its challenge. Same statuses as verify().
     *
     * @return array{ok: bool, status: string, user: User|null, retry_after: int}
     */
    public function verifyMagicLink(string $challengeId, string $token): array
    {
        $challenge = $this->loadChallenge($challengeId);
        if ($challenge === null || $challenge['channel'] !== self::CHANNEL_EMAIL_LINK) {
            return $this->verifyFailure('expired');
        }

        $email = $this->destination($challenge);
        if ($email === null) {
            $this->forgetChallenge($challengeId);

            return $this->verifyFailure('expired');
        }

        $user = $this->emailOtpService->consumeMagicLink($token, EmailOtp::PURPOSE_LOGIN, $email, allowInactive: true);
        if ($user === null) {
            return $this->verifyFailure('invalid');
        }

        $this->forgetChallenge($challengeId);

        return $this->verified($user);
    }

    /**
     * Check a code against its challenge. Statuses: verified, invalid, expired, rate_limited, busy,
     * unknown_account, inactive. Only verified and inactive carry a user.
     *
     * @return array{ok: bool, status: string, user: User|null, retry_after: int}
     */
    public function verify(string $challengeId, string $code, string $ip): array
    {
        $challenge = $this->loadChallenge($challengeId);
        if ($challenge === null) {
            return $this->verifyFailure('expired');
        }

        return match ($challenge['channel']) {
            self::CHANNEL_WHATSAPP => $this->verifyWhatsApp($challengeId, $challenge, $code),
            self::CHANNEL_EMAIL => $this->verifyEmail($challengeId, $challenge, $code, $ip),
            default => $this->verifyFailure('expired'),
        };
    }

    /**
     * @return array{ok: bool, status: string, challenge_id: string|null, expires_in: int, retry_after: int}
     */
    private function requestWhatsApp(string $destination, string $ip): array
    {
        $phone = WhatsAppOtpService::canonicalPhone($destination);
        if ($phone === null) {
            return $this->failure('invalid_destination');
        }

        $principal = $this->whatsAppPrincipal($phone);
        $issued = $this->whatsAppOtpService->issue(
            self::OTP_PURPOSE,
            self::OTP_SUBJECT,
            $principal,
            $phone,
            rotate: true,
            tenant: 'api-otp-ip:'.hash('sha256', $ip),
        );

        if (! ($issued['ok'] ?? false)) {
            return $this->failure(match ($issued['status'] ?? '') {
                'rate_limited' => 'rate_limited',
                'busy' => 'busy',
                default => 'delivery_failed',
            }, (int) ($issued['retry_after'] ?? 0));
        }

        $expiresAt = Carbon::parse((string) $issued['expires_at']);

        return $this->storeChallenge([
            'channel' => self::CHANNEL_WHATSAPP,
            'destination_encrypted' => Crypt::encryptString($phone),
            'principal' => $principal,
            'otp_challenge_id' => (string) $issued['challenge_id'],
            'expires_at' => $expiresAt->toIso8601String(),
        ], max(1, (int) now()->diffInSeconds($expiresAt, false)));
    }

    /**
     * @return array{ok: bool, status: string, challenge_id: string|null, expires_in: int, retry_after: int}
     */
    private function requestEmail(string $destination, string $ip, bool $magicLinkOnly = false): array
    {
        $email = EmailOtpService::normalizeEmail($destination);
        if ($email === null) {
            return $this->failure('invalid_destination');
        }

        $emailHash = hash('sha256', $email);
        $limits = [
            ['ontime:api-otp:email:send-5m:'.$emailHash, 3, 300],
            ['ontime:api-otp:email:send-1h:'.$emailHash, 10, 3600],
            ['ontime:api-otp:email:send-ip:'.hash('sha256', $ip), 20, 60],
        ];
        foreach ($limits as [$key, $maxAttempts]) {
            if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
                return $this->failure('rate_limited', RateLimiter::availableIn($key));
            }
        }
        foreach ($limits as [$key, , $decaySeconds]) {
            RateLimiter::hit($key, $decaySeconds);
        }

        // Inactive accounts still get the email so the API can refuse them with a clear 403 once the
        // code proves who is asking; unknown addresses silently move on to the code step.
        $user = $this->emailOtpService->findUser($email, includeInactive: true);
        if ($user !== null) {
            try {
                $this->emailOtpService->issue(
                    $user,
                    $email,
                    EmailOtp::PURPOSE_LOGIN,
                    $magicLinkOnly ? fn (string $token): string => route('app-login.link', ['token' => $token]) : null,
                    includeCode: ! $magicLinkOnly,
                );
            } catch (Throwable) {
                return $this->failure('delivery_failed');
            }
        }

        $expiresIn = $this->emailOtpService->expiresIn();

        return $this->storeChallenge([
            'channel' => $magicLinkOnly ? self::CHANNEL_EMAIL_LINK : self::CHANNEL_EMAIL,
            'destination_encrypted' => Crypt::encryptString($email),
            'expires_at' => now()->addSeconds($expiresIn)->toIso8601String(),
        ], $expiresIn);
    }

    /**
     * @param  array<string, mixed>  $challenge
     * @return array{ok: bool, status: string, user: User|null, retry_after: int}
     */
    private function verifyWhatsApp(string $challengeId, array $challenge, string $code): array
    {
        $verified = $this->whatsAppOtpService->verify(
            self::OTP_PURPOSE,
            self::OTP_SUBJECT,
            (string) ($challenge['principal'] ?? ''),
            (string) ($challenge['otp_challenge_id'] ?? ''),
            $code,
        );

        if (! ($verified['ok'] ?? false)) {
            $status = (string) ($verified['status'] ?? 'expired');
            if ($status === 'expired') {
                $this->forgetChallenge($challengeId);
            }

            return $this->verifyFailure(in_array($status, ['invalid', 'rate_limited', 'busy'], true) ? $status : 'expired', (int) ($verified['retry_after'] ?? 0));
        }

        $this->forgetChallenge($challengeId);

        $phone = WhatsAppOtpService::canonicalPhone($verified['phone'] ?? null);
        if ($phone === null || $phone !== $this->destination($challenge)) {
            return $this->verifyFailure('expired');
        }

        // Only numbers recorded by HR or an admin (on the account or its worker profile) can log in.
        $user = User::findByPhone($phone);
        if ($user === null) {
            return $this->verifyFailure('unknown_account');
        }

        return $this->verified($user);
    }

    /**
     * @param  array<string, mixed>  $challenge
     * @return array{ok: bool, status: string, user: User|null, retry_after: int}
     */
    private function verifyEmail(string $challengeId, array $challenge, string $code, string $ip): array
    {
        $email = $this->destination($challenge);
        if ($email === null) {
            $this->forgetChallenge($challengeId);

            return $this->verifyFailure('expired');
        }

        $limits = [
            ['ontime:api-otp:email:verify:'.hash('sha256', $email), 15, 300],
            ['ontime:api-otp:email:verify-ip:'.hash('sha256', $ip), 20, 60],
        ];
        foreach ($limits as [$key, $maxAttempts]) {
            if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
                return $this->verifyFailure('rate_limited', RateLimiter::availableIn($key));
            }
        }
        foreach ($limits as [$key, , $decaySeconds]) {
            RateLimiter::hit($key, $decaySeconds);
        }

        $user = $this->emailOtpService->verify($email, EmailOtp::PURPOSE_LOGIN, $code, allowInactive: true);
        if ($user === null) {
            return $this->verifyFailure('invalid');
        }

        $this->forgetChallenge($challengeId);

        return $this->verified($user);
    }

    /**
     * @return array{ok: bool, status: string, user: User|null, retry_after: int}
     */
    private function verified(User $user): array
    {
        if (! $user->is_active) {
            return ['ok' => false, 'status' => 'inactive', 'user' => $user, 'retry_after' => 0];
        }

        return ['ok' => true, 'status' => 'verified', 'user' => $user, 'retry_after' => 0];
    }

    private function whatsAppPrincipal(string $phone): string
    {
        return 'api-otp-phone:'.hash('sha256', $phone);
    }

    /**
     * @param  array<string, mixed>  $challenge
     * @return array{ok: bool, status: string, challenge_id: string|null, expires_in: int, retry_after: int}
     */
    private function storeChallenge(array $challenge, int $expiresIn): array
    {
        $challengeId = 'otp_'.Str::random(48);
        if (! Cache::put($this->challengeKey($challengeId), $challenge, now()->addSeconds($expiresIn))) {
            return $this->failure('delivery_failed');
        }

        return [
            'ok' => true,
            'status' => 'otp_sent',
            'challenge_id' => $challengeId,
            'expires_in' => $expiresIn,
            'retry_after' => 0,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadChallenge(string $challengeId): ?array
    {
        if (! preg_match('/^otp_[A-Za-z0-9]{48}$/D', $challengeId)) {
            return null;
        }

        $challenge = Cache::get($this->challengeKey($challengeId));
        if (! is_array($challenge) || now()->gte((string) ($challenge['expires_at'] ?? '1970-01-01'))) {
            $this->forgetChallenge($challengeId);

            return null;
        }

        return $challenge;
    }

    private function forgetChallenge(string $challengeId): void
    {
        Cache::forget($this->challengeKey($challengeId));
    }

    private function challengeKey(string $challengeId): string
    {
        return 'ontime:api-otp:challenge:'.hash('sha256', $challengeId);
    }

    /**
     * @param  array<string, mixed>  $challenge
     */
    private function destination(array $challenge): ?string
    {
        try {
            return Crypt::decryptString((string) ($challenge['destination_encrypted'] ?? ''));
        } catch (DecryptException) {
            return null;
        }
    }

    /**
     * @return array{ok: bool, status: string, challenge_id: string|null, expires_in: int, retry_after: int}
     */
    private function failure(string $status, int $retryAfter = 0): array
    {
        return ['ok' => false, 'status' => $status, 'challenge_id' => null, 'expires_in' => 0, 'retry_after' => max(0, $retryAfter)];
    }

    /**
     * @return array{ok: bool, status: string, user: User|null, retry_after: int}
     */
    private function verifyFailure(string $status, int $retryAfter = 0): array
    {
        return ['ok' => false, 'status' => $status, 'user' => null, 'retry_after' => max(0, $retryAfter)];
    }
}
