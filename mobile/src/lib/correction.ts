import type { Attendance, PaginationA, User } from '@/lib/api/types';
import { formatTime, toDateString } from '@/lib/format';

/** The server's own refusal for a check-out-only correction where the session has no check-in. */
export const CHECK_IN_REQUIRED_MESSAGE = 'Belum ada jam masuk pada jadwal ini. Isi juga jam masuk yang benar, bukan hanya jam pulang.';

/** The server's own refusal for a check-out-only correction on a date whose attendance was rejected (under `date`). */
export const REJECTED_ATTENDANCE_MESSAGE =
  'Absensi pada tanggal ini sudah ditolak oleh atasan. Bila Anda memang bekerja, ajukan koreksi dengan jam masuk (dan jam pulang) yang sebenarnya.';

/** A message the phone shows before sending, under the field the server would put it. */
export interface CorrectionClientError {
  field: 'date' | 'corrected_check_in';
  message: string;
}

/** One attendance of the date the worker can name as the one corrected. */
export interface CorrectionTarget {
  attendance_id: number;
  schedule_id: number | null;
  label: string;
}

/**
 * The `date` search param as a `YYYY-MM-DD` the form can start with (the home card's "belum absen
 * pulang" notice passes the day to correct), or null when absent, malformed, or after today.
 */
export function initialCorrectionDate(param: string | string[] | undefined, today: Date = new Date()): string | null {
  const value = Array.isArray(param) ? param[0] : param;
  if (!value || !/^\d{4}-\d{2}-\d{2}$/.test(value)) {
    return null;
  }
  return value <= toDateString(today) ? value : null;
}

/**
 * The signed-in worker's own rows: `/attendances` also lists the team a coordinator or PIC can see, and a
 * correction is only ever for the worker's own sessions. Nothing matches without a worker profile.
 */
export function ownAttendanceRows<T extends Pick<Attendance, 'employee_id' | 'intern_id' | 'freelancer_id'>>(
  rows: T[] | null | undefined,
  user: Pick<User, 'employee' | 'intern' | 'freelancer'> | null | undefined,
): T[] | null {
  if (!rows) {
    return null;
  }
  const own = (row: T): boolean => {
    if (user?.employee) {
      return row.employee_id === user.employee.id;
    }
    if (user?.intern) {
      return row.intern_id === user.intern.id;
    }
    if (user?.freelancer) {
      return row.freelancer_id === user.freelancer.id;
    }
    return false;
  };
  return rows.filter(own);
}

/**
 * The attendances of the date to choose from: only on a date with several, so a plain day keeps the old form
 * and the server infers the target. Labelled by schedule (newer servers) and check-in time.
 */
export function correctionTargets(rows: Attendance[] | null | undefined): CorrectionTarget[] {
  if (!rows || rows.length < 2) {
    return [];
  }
  return [...rows]
    .sort((a, b) => (a.check_in ?? '').localeCompare(b.check_in ?? ''))
    .map((row, index) => ({
      attendance_id: row.id,
      schedule_id: row.schedule_id ?? null,
      label: [row.schedule_label ?? `Jadwal ${index + 1}`, row.check_in ? `masuk ${formatTime(row.check_in)}` : 'belum masuk'].join(' · '),
    }));
}

/**
 * Checked on the phone before sending: a correction with only the check-out time needs a session that was
 * checked in and not rejected, with the server's own message under the server's own field. Answered only when
 * the worker's attendance of the date is known and the session is clear (the only one, or the one picked);
 * otherwise the server decides.
 */
export function correctionClientError(input: {
  checkIn: string | null;
  checkOut: string | null;
  /** The worker's own attendances of the date, or null while unknown (loading, offline, error). */
  rows: Pick<Attendance, 'id' | 'check_in' | 'status'>[] | null | undefined;
  pickedAttendanceId: number | null;
  /**
   * Whether `rows` holds every own attendance of the date (see isCompleteListing). When it may not, a missing
   * or lone session proves nothing and only a picked row is checked. Defaults to complete.
   */
  complete?: boolean;
}): CorrectionClientError | null {
  const { checkIn, checkOut, rows, pickedAttendanceId, complete = true } = input;
  if (!checkOut || checkIn || !rows) {
    return null;
  }
  if (rows.length === 0) {
    return complete ? { field: 'corrected_check_in', message: CHECK_IN_REQUIRED_MESSAGE } : null;
  }
  const inferred = complete && rows.length === 1 ? rows[0] : undefined;
  const target = pickedAttendanceId !== null ? rows.find((row) => row.id === pickedAttendanceId) : inferred;
  if (!target) {
    return null;
  }
  if (target.status === 'rejected') {
    return { field: 'date', message: REJECTED_ATTENDANCE_MESSAGE };
  }
  return target.check_in ? null : { field: 'corrected_check_in', message: CHECK_IN_REQUIRED_MESSAGE };
}

/**
 * Whether a page of `/attendances` is the whole listing: the form reads only the first page, and an older
 * server ignores `mine=1` and pages a coordinator's whole team (20 rows a page), so the worker's own row
 * may sit on a later page. Without pagination figures it cannot be told, so it is not complete.
 */
export function isCompleteListing(pagination: Pick<PaginationA, 'last_page'> | null | undefined): boolean {
  return pagination?.last_page !== undefined && pagination.last_page <= 1;
}
