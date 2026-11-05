<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One mobile app install that can receive Expo push notifications for a user.
 *
 * @property int $id
 * @property int $user_id
 * @property string $token
 * @property string $platform
 * @property Carbon|null $last_seen_at
 */
class DeviceToken extends Model
{
    public const PLATFORMS = ['ios', 'android'];

    /** Expo push tokens look like ExponentPushToken[xxxxxxxxxxxxxxxxxxxxxx]. */
    public const TOKEN_PATTERN = '/^Expo(nent)?PushToken\[[A-Za-z0-9_-]+\]$/';

    protected $fillable = [
        'user_id',
        'token',
        'platform',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
