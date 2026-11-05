<?php

namespace App\Services;

use App\Mail\EmailLoginMail;
use App\Models\EmailOtp;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Passwordless email login, the same pattern as gais-web, dnd-web and helpdesk-web: one email holds a
 * 6-digit code and a magic link. Both live in one email_otps record, so using either one also uses up
 * the other.
 */
class EmailOtpService
{
    public const MAX_ATTEMPTS = 5;

    public const TOKEN_LENGTH = 64;

    /**
     * Email login is only offered when the default mailer really sends mail (not log or array).
     */
    public static function isConfigured(): bool
    {
        $mailer = (string) config('mail.default');
        $transport = (string) config("mail.mailers.{$mailer}.transport");

        return ! in_array($transport, ['', 'log', 'array'], true)
            && filled(config('mail.from.address'));
    }

    public static function normalizeEmail(mixed $value): ?string
    {
        $email = Str::lower(trim(is_scalar($value) ? (string) $value : ''));

        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : null;
    }

    /**
     * The active account that owns this email. An email shared by more than one account cannot log in.
     * With $includeInactive the inactive owner is returned too, for callers that refuse it themselves.
     */
    public function findUser(string $email, bool $includeInactive = false): ?User
    {
        $matches = User::query()
            ->whereRaw('LOWER(TRIM(email)) = ?', [$email])
            ->limit(2)
            ->get();

        $user = $matches->count() === 1 ? $matches->first() : null;

        return $user?->is_active || ($user && $includeInactive) ? $user : null;
    }

    /**
     * Create a new code and magic link (earlier ones for this email stop working) and send them right away.
     *
     * @throws RuntimeException when the email cannot be sent; the record is expired first.
     */
    public function issue(User $user, string $email, string $purpose): void
    {
        $expiresIn = $this->expiresIn();
        $lockKey = 'ontime:email-otp:lock:issue:'.hash('sha256', $purpose."\0".$email);

        Cache::lock($lockKey, 90)->block(10, function () use ($user, $email, $purpose, $expiresIn): void {
            $otp = (string) random_int(100000, 999999);
            $token = Str::random(self::TOKEN_LENGTH);

            $record = DB::transaction(function () use ($user, $email, $purpose, $otp, $token, $expiresIn): EmailOtp {
                EmailOtp::query()
                    ->where('email', $email)
                    ->where('purpose', $purpose)
                    ->whereNull('verified_at')
                    ->update(['expires_at' => now()]);

                return EmailOtp::query()->create([
                    'user_id' => $user->getKey(),
                    'email' => $email,
                    'purpose' => $purpose,
                    'otp_hash' => Hash::make($otp),
                    'token_hash' => hash('sha256', $token),
                    'expires_at' => now()->addSeconds($expiresIn),
                    'attempt_count' => 0,
                ]);
            });

            try {
                Mail::to($email)->send(new EmailLoginMail(
                    name: (string) $user->name,
                    otp: $otp,
                    loginUrl: route('email-login.link', ['token' => $token]),
                    expiresInMinutes: max(1, intdiv($expiresIn, 60)),
                ));
            } catch (Throwable $exception) {
                $record->forceFill(['expires_at' => now()])->save();

                Log::warning('Gagal mengirim email login.', [
                    'user_id' => $user->getKey(),
                    'purpose' => $purpose,
                    'email_domain' => Str::after($email, '@'),
                    'exception' => $exception::class,
                ]);

                throw new RuntimeException('Gagal mengirim email login.', previous: $exception);
            }
        });
    }

    /**
     * Check the code against the latest request for this email. A wrong code counts an attempt; after
     * MAX_ATTEMPTS the record, magic link included, stops working. With $allowInactive a correct code
     * also returns an inactive account, for callers that refuse it with their own message.
     */
    public function verify(string $email, string $purpose, string $otp, bool $allowInactive = false): ?User
    {
        $candidate = EmailOtp::query()
            ->where('email', $email)
            ->where('purpose', $purpose)
            ->whereNull('verified_at')
            ->latest('id')
            ->first(['id', 'user_id']);

        return $candidate ? $this->redeem($candidate, $otp, $allowInactive) : null;
    }

    public function consumeMagicLink(string $token, string $purpose): ?User
    {
        if (strlen($token) !== self::TOKEN_LENGTH) {
            return null;
        }

        $candidate = EmailOtp::query()
            ->where('token_hash', hash('sha256', $token))
            ->where('purpose', $purpose)
            ->first(['id', 'user_id']);

        return $candidate ? $this->redeem($candidate, null) : null;
    }

    public function expiresIn(): int
    {
        return max(60, (int) config('services.email_login.expires_in', 600));
    }

    /**
     * The user row is locked before the code row, in the same order for code and magic link, so a
     * change of the user's email cannot race a login in progress.
     */
    private function redeem(EmailOtp $candidate, ?string $otp, bool $allowInactive = false): ?User
    {
        if ($candidate->user_id === null) {
            return null;
        }

        return DB::transaction(function () use ($candidate, $otp, $allowInactive): ?User {
            $user = User::query()->whereKey($candidate->user_id)->lockForUpdate()->first();
            $record = EmailOtp::query()
                ->whereKey($candidate->getKey())
                ->whereNull('verified_at')
                ->lockForUpdate()
                ->first();

            if (! $user || ! $record || (! $user->is_active && ! $allowInactive) || ! $this->isUsable($record)
                || self::normalizeEmail($user->email) !== $record->email) {
                return null;
            }

            if ($otp !== null && ! Hash::check($otp, $record->otp_hash)) {
                $attempts = $record->attempt_count + 1;
                $record->forceFill([
                    'attempt_count' => $attempts,
                    'expires_at' => $attempts >= self::MAX_ATTEMPTS ? now() : $record->expires_at,
                ])->save();

                return null;
            }

            $record->forceFill(['verified_at' => now()])->save();

            // A code or link that reached the inbox proves the user owns the address.
            if ($user->email_verified_at === null) {
                $user->forceFill(['email_verified_at' => now()])->save();
            }

            return $user;
        });
    }

    private function isUsable(EmailOtp $record): bool
    {
        return $record->expires_at !== null
            && $record->expires_at->isFuture()
            && $record->attempt_count < self::MAX_ATTEMPTS;
    }
}
