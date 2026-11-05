<?php

namespace App\Support;

use App\Models\User;
use App\Services\Attendance\AttendanceRuleException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * Encrypted, short lived proof that a selfie was uploaded by a given user.
 *
 * The web panel never sends storage paths to the server: the upload endpoint answers with a token
 * and check-in only accepts tokens issued to the same user, so nobody can submit another stored
 * file (for example their own master face photo) as the attendance selfie.
 */
final class SelfieUploadToken
{
    public const VALID_FOR_MINUTES = 15;

    public const INVALID_MESSAGE = 'Foto selfie tidak valid atau kedaluwarsa. Ambil ulang foto.';

    public static function issue(string $path, User $user): string
    {
        return Crypt::encryptString(json_encode([
            'path' => $path,
            'user_id' => $user->getKey(),
            'expires' => now()->addMinutes(self::VALID_FOR_MINUTES)->timestamp,
        ]));
    }

    /**
     * @param  bool  $allowExpired  Accept an expired token. Only for comparing it with a path that is
     *                              already stored, never for accepting a new upload.
     *
     * @throws AttendanceRuleException When the token is malformed, expired or issued to someone else.
     */
    public static function resolve(string $token, User $user, bool $allowExpired = false): string
    {
        try {
            $payload = json_decode(Crypt::decryptString($token), true);
        } catch (DecryptException) {
            throw self::invalid();
        }

        if (! is_array($payload)
            || ! is_string($payload['path'] ?? null)
            || $payload['path'] === ''
            || (string) ($payload['user_id'] ?? '') !== (string) $user->getKey()
            || ! is_int($payload['expires'] ?? null)
            || (! $allowExpired && $payload['expires'] < now()->timestamp)) {
            throw self::invalid();
        }

        return $payload['path'];
    }

    private static function invalid(): AttendanceRuleException
    {
        return new AttendanceRuleException(self::INVALID_MESSAGE, 422, 'photo');
    }
}
