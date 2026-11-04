<?php

namespace App\Services;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * One-time codes sent over WhatsApp, the same pattern as gais-web, dnd-web and helpdesk-web.
 *
 * A challenge lives only in the cache. It is bound to a purpose, a subject and a principal (the
 * browser session), stores the code as a hash and the phone encrypted, and is limited per phone,
 * per session and per IP.
 */
class WhatsAppOtpService
{
    private const PURPOSES = ['login'];

    /**
     * WhatsApp login is only offered when the WhatsApp Gateway is configured.
     */
    public static function isConfigured(): bool
    {
        return filled(config('services.whatsapp_gateway.url')) && filled(config('services.whatsapp_gateway.token'));
    }

    /**
     * Indonesian mobile number as 62xxxxxxxxxx, or null when it is not a usable number.
     */
    public static function canonicalPhone(mixed $phone): ?string
    {
        $normalized = WhatsAppNotificationService::normalizePhoneNumber(is_scalar($phone) ? (string) $phone : null);

        return $normalized !== null && preg_match('/^62[0-9]{8,13}$/D', $normalized) === 1 ? $normalized : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function issue(
        string $purpose,
        string $subject,
        string $principal,
        string $phone,
        bool $rotate = false,
        string $tenant = '',
    ): array {
        $scope = $this->scope($purpose, $subject, $principal);
        $phone = self::canonicalPhone($phone);
        if ($scope === null || $phone === null) {
            return $this->failure('invalid_phone');
        }

        $scopeHash = hash('sha256', $scope);
        $phoneHash = hash('sha256', $phone);
        $aliasKey = $this->scopeAliasKey($purpose, $scopeHash);
        $lock = Cache::lock('ontime:phone-otp:lock:issue:'.$scopeHash, 30);
        if (! $lock->get()) {
            return $this->failure('busy', 1);
        }

        try {
            $existingId = (string) Cache::get($aliasKey, '');
            if (! $rotate && $existingId !== '') {
                $existing = Cache::get($this->challengeKey($purpose, $existingId));
                if (is_array($existing)
                    && hash_equals((string) ($existing['scope_hash'] ?? ''), $scopeHash)
                    && hash_equals((string) ($existing['phone_hash'] ?? ''), $phoneHash)
                    && now()->lt((string) ($existing['expires_at'] ?? '1970-01-01'))
                ) {
                    return $this->issued($existingId, $phone, (string) $existing['expires_at'], true);
                }

                Cache::forget($aliasKey);
            }

            $targetLimiter = 'ontime:phone-otp:send:target:'.$phoneHash;
            $principalLimiter = 'ontime:phone-otp:send:principal:'.hash('sha256', $principal);
            $tenant = trim($tenant) !== '' ? trim($tenant) : $principal;
            $tenantHash = hash('sha256', $tenant);
            $tenantLimiter = 'ontime:phone-otp:send:tenant:'.$tenantHash;
            $principalLock = Cache::lock('ontime:phone-otp:lock:principal:'.hash('sha256', $principal), 30);
            if (! $principalLock->get()) {
                return $this->failure('busy', 1);
            }

            try {
                $targetLock = Cache::lock('ontime:phone-otp:lock:target:'.$phoneHash, 30);
                if (! $targetLock->get()) {
                    return $this->failure('busy', 1);
                }

                try {
                    $quotaLock = Cache::lock('ontime:phone-otp:lock:tenant-quota:'.$tenantHash, 10);
                    if (! $quotaLock->get()) {
                        return $this->failure('busy', 1);
                    }

                    try {
                        if (RateLimiter::tooManyAttempts($targetLimiter, 3)
                            || RateLimiter::tooManyAttempts($principalLimiter, 10)
                            || RateLimiter::tooManyAttempts($tenantLimiter, 30)) {
                            return $this->failure('rate_limited', max(
                                RateLimiter::availableIn($targetLimiter),
                                RateLimiter::availableIn($principalLimiter),
                                RateLimiter::availableIn($tenantLimiter),
                            ));
                        }

                        // Reserve every quota at once. Gateway failures still count, so an outage
                        // cannot be used to send many codes in parallel.
                        RateLimiter::hit($targetLimiter, 120);
                        RateLimiter::hit($principalLimiter, 60);
                        RateLimiter::hit($tenantLimiter, 60);
                    } finally {
                        $quotaLock->release();
                    }

                    if ($existingId !== '') {
                        Cache::forget($this->challengeKey($purpose, $existingId));
                    }

                    $challengeId = 'wotp_'.Str::random(48);
                    $otp = (string) random_int(100000, 999999);
                    $ttlMinutes = $this->ttlMinutes();
                    $expiresAt = now()->addMinutes($ttlMinutes);
                    $challengeKey = $this->challengeKey($purpose, $challengeId);
                    $stored = Cache::put($challengeKey, [
                        'version' => 1,
                        'scope_hash' => $scopeHash,
                        'subject_hash' => hash('sha256', $subject),
                        'principal_hash' => hash('sha256', $principal),
                        'phone_encrypted' => Crypt::encryptString($phone),
                        'phone_hash' => $phoneHash,
                        'otp_hash' => Hash::make($otp),
                        'expires_at' => $expiresAt->toIso8601String(),
                    ], $expiresAt);
                    if (! $stored || ! Cache::put($aliasKey, $challengeId, $expiresAt)) {
                        Cache::forget($challengeKey);
                        Cache::forget($aliasKey);

                        return $this->failure('storage_failed');
                    }

                    if (! (WhatsAppNotificationService::sendOtpCode($phone, $otp, $ttlMinutes)['api_sent'] ?? false)) {
                        Cache::forget($challengeKey);
                        Cache::forget($aliasKey);

                        return $this->failure('delivery_failed');
                    }

                    return $this->issued($challengeId, $phone, $expiresAt->toIso8601String(), false);
                } finally {
                    $targetLock->release();
                }
            } finally {
                $principalLock->release();
            }
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function verify(
        string $purpose,
        string $subject,
        string $principal,
        string $challengeId,
        string $code,
    ): array {
        $scope = $this->scope($purpose, $subject, $principal);
        if ($scope === null || ! preg_match('/^wotp_[A-Za-z0-9]{48}$/D', $challengeId)) {
            return $this->failure('expired');
        }

        $scopeHash = hash('sha256', $scope);
        $challengeKey = $this->challengeKey($purpose, $challengeId);
        $verifyLimiter = 'ontime:phone-otp:verify:'.hash('sha256', $scope."\0".$challengeId);
        $lock = Cache::lock('ontime:phone-otp:lock:issue:'.$scopeHash, 30);
        if (! $lock->get()) {
            return $this->failure('busy', 1);
        }

        try {
            if (RateLimiter::tooManyAttempts($verifyLimiter, 5)) {
                return $this->failure('rate_limited', RateLimiter::availableIn($verifyLimiter));
            }

            $challenge = Cache::get($challengeKey);
            if (! is_array($challenge)
                || ! hash_equals((string) ($challenge['scope_hash'] ?? ''), $scopeHash)
                || now()->gte((string) ($challenge['expires_at'] ?? '1970-01-01'))
            ) {
                return $this->failure('expired');
            }

            if (! preg_match('/^[0-9]{6}$/D', trim($code))
                || ! Hash::check(trim($code), (string) ($challenge['otp_hash'] ?? ''))) {
                RateLimiter::hit($verifyLimiter, $this->ttlMinutes() * 60);

                return $this->failure('invalid');
            }

            try {
                $phone = Crypt::decryptString((string) ($challenge['phone_encrypted'] ?? ''));
            } catch (DecryptException) {
                return $this->failure('expired');
            }

            if (! hash_equals((string) ($challenge['phone_hash'] ?? ''), hash('sha256', $phone))) {
                return $this->failure('expired');
            }

            Cache::forget($challengeKey);
            $aliasKey = $this->scopeAliasKey($purpose, $scopeHash);
            if (hash_equals($challengeId, (string) Cache::get($aliasKey, ''))) {
                Cache::forget($aliasKey);
            }
            RateLimiter::clear($verifyLimiter);

            return [
                'ok' => true,
                'status' => 'verified',
                'phone' => $phone,
                'retry_after' => 0,
            ];
        } finally {
            $lock->release();
        }
    }

    public function revoke(string $purpose, string $subject, string $principal): bool
    {
        $scope = $this->scope($purpose, $subject, $principal);
        if ($scope === null) {
            return false;
        }

        $scopeHash = hash('sha256', $scope);
        $lock = Cache::lock('ontime:phone-otp:lock:issue:'.$scopeHash, 30);
        if (! $lock->get()) {
            return false;
        }

        try {
            $aliasKey = $this->scopeAliasKey($purpose, $scopeHash);
            $challengeId = (string) Cache::get($aliasKey, '');
            if ($challengeId !== '') {
                Cache::forget($this->challengeKey($purpose, $challengeId));
            }
            Cache::forget($aliasKey);

            return true;
        } finally {
            $lock->release();
        }
    }

    private function scope(string $purpose, string $subject, string $principal): ?string
    {
        $purpose = trim($purpose);
        $subject = trim($subject);
        $principal = trim($principal);
        if (! in_array($purpose, self::PURPOSES, true)
            || $subject === '' || Str::length($subject) > 255
            || $principal === '' || Str::length($principal) > 255) {
            return null;
        }

        return $purpose."\0".$subject."\0".$principal;
    }

    private function challengeKey(string $purpose, string $challengeId): string
    {
        return "ontime:phone-otp:{$purpose}:challenge:".hash('sha256', $challengeId);
    }

    private function scopeAliasKey(string $purpose, string $scopeHash): string
    {
        return "ontime:phone-otp:{$purpose}:scope:{$scopeHash}";
    }

    private function ttlMinutes(): int
    {
        return max(1, (int) config('services.whatsapp_otp.ttl_minutes', 5));
    }

    /**
     * @return array<string, mixed>
     */
    private function issued(string $challengeId, string $phone, string $expiresAt, bool $replayed): array
    {
        return [
            'ok' => true,
            'status' => 'otp_sent',
            'challenge_id' => $challengeId,
            'phone' => $phone,
            'expires_at' => $expiresAt,
            'retry_after' => 0,
            'replayed' => $replayed,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function failure(string $status, int $retryAfter = 0): array
    {
        return [
            'ok' => false,
            'status' => $status,
            'challenge_id' => null,
            'phone' => null,
            'expires_at' => null,
            'retry_after' => max(0, $retryAfter),
        ];
    }
}
