<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One email login request. The 6-digit code and the magic link token live in the same record, so
 * using either one also uses up the other.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $email
 * @property string $purpose
 * @property string $otp_hash
 * @property string $token_hash
 * @property Carbon $expires_at
 * @property Carbon|null $verified_at
 * @property int $attempt_count
 */
class EmailOtp extends Model
{
    public const PURPOSE_LOGIN = 'login';

    protected $fillable = [
        'user_id',
        'email',
        'purpose',
        'otp_hash',
        'token_hash',
        'expires_at',
        'verified_at',
        'attempt_count',
    ];

    protected $hidden = [
        'otp_hash',
        'token_hash',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'verified_at' => 'datetime',
            'attempt_count' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
