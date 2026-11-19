<?php

namespace App\Services;

use App\Models\ShiftSchedule;
use App\Models\ShiftScheduleChange;
use Closure;
use Illuminate\Support\Facades\Context;

/**
 * Where the roster change being made right now comes from (panel, api, import, copy, swap, cover,
 * correction, lifecycle) and why, for the change log {@see ShiftScheduleChange} that the
 * {@see ShiftSchedule} model events write. Kept in the request-scoped Laravel Context (hidden keys), so
 * nothing leaks into the next request of a long-lived worker process.
 *
 * Only the source "correction" may change a schedule that already has attendance (a locked schedule).
 */
final class RosterChangeContext
{
    public const SOURCE_KEY = 'roster.source';

    public const REASON_KEY = 'roster.reason';

    /** Source logged when a schedule is written outside any context. */
    public const DEFAULT_SOURCE = 'system';

    /**
     * Run the callback with the given source and reason; the previous ones are restored afterwards.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function run(string $source, ?string $reason, Closure $callback): mixed
    {
        $hadSource = Context::hasHidden(self::SOURCE_KEY);
        $hadReason = Context::hasHidden(self::REASON_KEY);
        $previousSource = Context::getHidden(self::SOURCE_KEY);
        $previousReason = Context::getHidden(self::REASON_KEY);

        Context::addHidden(self::SOURCE_KEY, $source);
        Context::addHidden(self::REASON_KEY, filled($reason) ? mb_substr(trim((string) $reason), 0, 255) : null);

        try {
            return $callback();
        } finally {
            $hadSource ? Context::addHidden(self::SOURCE_KEY, $previousSource) : Context::forgetHidden(self::SOURCE_KEY);
            $hadReason ? Context::addHidden(self::REASON_KEY, $previousReason) : Context::forgetHidden(self::REASON_KEY);
        }
    }

    /**
     * The source and reason of the roster change being made now.
     *
     * @return array{source: string, reason: string|null}
     */
    public static function current(): array
    {
        return [
            'source' => (string) (Context::getHidden(self::SOURCE_KEY) ?? self::DEFAULT_SOURCE),
            'reason' => Context::getHidden(self::REASON_KEY),
        ];
    }

    /**
     * Whether the change being made now is an approved attendance correction, the only writer that may
     * change a locked schedule.
     */
    public static function isCorrection(): bool
    {
        return self::current()['source'] === 'correction';
    }
}
