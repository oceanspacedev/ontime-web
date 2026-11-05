<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * API tokens (the mobile app's sessions) end after a stretch without use, set by
 * sanctum.idle_expiration in minutes. A token in daily use never runs out; one left on a lost or
 * old phone stops working on its own. Null turns the limit off.
 */
class IdleApiTokens
{
    public static function cutoff(): ?Carbon
    {
        $minutes = config('sanctum.idle_expiration');

        return filled($minutes) && (int) $minutes > 0 ? now()->subMinutes((int) $minutes) : null;
    }

    public static function isIdle(PersonalAccessToken $token): bool
    {
        $cutoff = self::cutoff();
        $lastActivity = $token->last_used_at ?? $token->created_at;

        return $cutoff !== null && $lastActivity !== null && $lastActivity->lt($cutoff);
    }

    /**
     * Delete the tokens that can no longer be used anyway. Returns how many were removed.
     */
    public static function prune(): int
    {
        $cutoff = self::cutoff();
        if ($cutoff === null) {
            return 0;
        }

        return PersonalAccessToken::query()
            ->where(fn ($query) => $query
                ->where('last_used_at', '<', $cutoff)
                ->orWhere(fn ($query) => $query->whereNull('last_used_at')->where('created_at', '<', $cutoff)))
            ->delete();
    }
}
