/**
 * Overtime (lembur) rules shown before submitting, mirroring the backend (OvertimeRequestService).
 * The server stays the judge: it derives the duration itself and refuses overlapping requests.
 */

export const MAX_OVERTIME_MINUTES = 12 * 60;
export const MAX_OVERTIME_DAYS_BACK = 31;
export const MAX_OVERTIME_DAYS_AHEAD = 31;

function toMinutes(time: string): number | null {
  const match = /^(\d{1,2}):(\d{2})/.exec(time);
  if (!match) {
    return null;
  }
  const hours = Number(match[1]);
  const minutes = Number(match[2]);
  if (hours > 23 || minutes > 59) {
    return null;
  }
  return hours * 60 + minutes;
}

/** Minutes from start to end; an end before the start runs past midnight. Null when missing or equal. */
export function overtimeDurationMinutes(start: string | null, end: string | null): number | null {
  if (!start || !end) {
    return null;
  }
  const from = toMinutes(start);
  const until = toMinutes(end);
  if (from === null || until === null || from === until) {
    return null;
  }
  return until > from ? until - from : until + 24 * 60 - from;
}

export function crossesMidnight(start: string | null, end: string | null): boolean {
  if (!start || !end) {
    return false;
  }
  const from = toMinutes(start);
  const until = toMinutes(end);
  return from !== null && until !== null && until < from;
}

/** Error for the end time field, or null when the range is valid. */
export function overtimeTimeError(start: string | null, end: string | null): string | null {
  if (!start || !end) {
    return null;
  }
  const minutes = overtimeDurationMinutes(start, end);
  if (minutes === null) {
    return 'Jam selesai tidak boleh sama dengan jam mulai.';
  }
  if (minutes > MAX_OVERTIME_MINUTES) {
    return 'Durasi lembur maksimal 12 jam.';
  }
  return null;
}

function startOfDay(date: Date): Date {
  return new Date(date.getFullYear(), date.getMonth(), date.getDate());
}

function addDays(date: Date, days: number): Date {
  return new Date(date.getFullYear(), date.getMonth(), date.getDate() + days);
}

/** Earliest date the picker offers: today minus 31 days. */
export function earliestOvertimeDate(today = new Date()): Date {
  return addDays(startOfDay(today), -MAX_OVERTIME_DAYS_BACK);
}

/** Latest date the picker offers: today plus 31 days. */
export function latestOvertimeDate(today = new Date()): Date {
  return addDays(startOfDay(today), MAX_OVERTIME_DAYS_AHEAD);
}

/** Error for a `YYYY-MM-DD` overtime date outside today -/+ 31 days, else null. */
export function overtimeDateError(date: string | null, today = new Date()): string | null {
  const match = date ? /^(\d{4})-(\d{2})-(\d{2})$/.exec(date) : null;
  if (!match) {
    return null;
  }
  const day = new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]));
  if (day < earliestOvertimeDate(today)) {
    return `Tanggal lembur paling lama ${MAX_OVERTIME_DAYS_BACK} hari ke belakang.`;
  }
  if (day > latestOvertimeDate(today)) {
    return `Tanggal lembur paling jauh ${MAX_OVERTIME_DAYS_AHEAD} hari ke depan.`;
  }
  return null;
}
