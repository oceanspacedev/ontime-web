<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Shift;

/**
 * Keeps the hours of a shift that payroll history depends on.
 *
 * Rekap Tim and laporan absensi read Jam Terjadwal from the shift a schedule points at. Deleting that shift
 * nulls the schedule's shift_id and changing its hours rewrites every past day, so the scheduled hours of a
 * closed month would change after the fact. A shift that is used by any schedule or attendance (also the
 * cover shift of an off-schedule check-in) is therefore deactivated instead of deleted, and the hours of a
 * shift used before today are locked: new hours are a new shift.
 */
final class ShiftUsageService
{
    /**
     * Why the shift cannot be deleted, or null when nothing refers to it. The model's own deleting guard
     * applies the same rule, so a delete from outside the panel cannot null history either.
     */
    public function deletionBlockedReason(Shift $shift): ?string
    {
        return $shift->deletionBlockedReason();
    }

    /**
     * Why the shift's hours cannot be changed any more, or null while it has not been worked or rostered on a
     * past day.
     */
    public function hoursLockedReason(Shift $shift): ?string
    {
        if (! $shift->exists) {
            return null;
        }

        $today = today()->toDateString();
        $usedBefore = $shift->schedules()->where('date', '<', $today)->exists()
            || Attendance::query()->where('shift_id', $shift->getKey())->where('date', '<', $today)->exists();

        return $usedBefore
            ? 'Jam shift ini terkunci karena sudah dipakai di jadwal atau absensi sebelumnya. Untuk jam yang berbeda, buat shift baru lalu nonaktifkan shift ini.'
            : null;
    }
}
