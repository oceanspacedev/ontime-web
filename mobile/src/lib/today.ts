import type { DayTotals, TodayAttendance, TodayScheduleItem, UnclosedPrevious } from './api/types';
import { formatDate, formatMinutes, formatTime, toDateString } from './format';

/** Milliseconds from `now` until the next local midnight, plus a second so the new date has begun. */
export function msUntilNextDay(now: Date): number {
  const next = new Date(now.getFullYear(), now.getMonth(), now.getDate() + 1, 0, 0, 1);
  return Math.max(1000, next.getTime() - now.getTime());
}

/**
 * The home card's notice about an earlier shift that was never checked out (yesterday, or a few days back
 * when days off came in between). The date is always named so the correction is filed for the right day.
 * A correction with the real check-out time fixes it; checking out now would record the wrong time, and
 * today's check-in stays open.
 */
export function unclosedNotice(
  unclosed: UnclosedPrevious | null | undefined,
  today: string = toDateString(new Date()),
): { message: string; canRequestCorrection: boolean } | null {
  if (!unclosed) {
    return null;
  }
  const [y, m, d] = today.split('-').map(Number);
  const isYesterday = unclosed.date === toDateString(new Date(y, m - 1, d - 1));
  const shown = formatDate(unclosed.date, 'short');
  const day = isYesterday ? `kemarin (${shown})` : `tanggal ${shown}`;
  const capitalize = (text: string) => `${text.charAt(0).toUpperCase()}${text.slice(1)}`;
  const detail = [unclosed.shift ? `Shift ${unclosed.shift}` : null, unclosed.check_in ? `masuk ${formatTime(unclosed.check_in)}` : null].filter(Boolean).join(', ');
  if (unclosed.correction_pending) {
    return { message: `Koreksi absen pulang ${day}${detail ? ` · ${detail}` : ''} sedang menunggu persetujuan.`, canRequestCorrection: false };
  }
  return {
    message: `${capitalize(day)} belum absen pulang — ajukan koreksi dengan jam pulang yang sebenarnya.${detail ? ` ${capitalize(detail)}.` : ''} Absen masuk hari ini tetap bisa.`,
    canRequestCorrection: true,
  };
}

/**
 * How many working schedules the day has. The server sends one `schedules` item per session segment, so a
 * split shift (07:45–13:00 & 17:45–22:00) is two items of one schedule: items are counted by `schedule_id`,
 * and standing company hours without a roster row (no id) by their sequence. Zero on an older server.
 */
export function workingScheduleCount(today: Pick<TodayAttendance, 'schedules'> | null | undefined): number {
  const working = (today?.schedules ?? []).filter((item) => !item.is_day_off);
  return new Set(working.map((item) => (item.schedule_id !== null ? `id:${item.schedule_id}` : `seq:${item.sequence}`))).size;
}

/**
 * The day's sessions the home card lists: only on a day with more than one working schedule, so a plain
 * day (a split shift included) looks as before. Empty on an older server, which sends no `schedules`.
 */
export function listedSchedules(today: Pick<TodayAttendance, 'schedules'>): TodayScheduleItem[] {
  return workingScheduleCount(today) > 1 ? (today.schedules ?? []).filter((item) => !item.is_day_off) : [];
}

/** One listed schedule: "Pagi · 08:00–16:00 · JChicken Ciledug", with "(s.d. besok)" for a shift past midnight. */
export function scheduleItemLine(item: Pick<TodayScheduleItem, 'shift' | 'start_time' | 'end_time' | 'crosses_midnight' | 'outlet' | 'section'>): string {
  const hours = item.start_time ? `${formatTime(item.start_time)}–${formatTime(item.end_time)}${item.crosses_midnight ? ' (s.d. besok)' : ''}` : null;
  return [item.shift?.name ?? 'Jam kerja', hours, item.outlet?.name, item.section].filter(Boolean).join(' · ');
}

/** What one listed schedule's attendance says: "Masuk 08:02 · Pulang 16:05", "Belum absen pulang", or null before check-in. */
export function scheduleItemAttendance(item: Pick<TodayScheduleItem, 'attendance'>): string | null {
  const attendance = item.attendance;
  if (!attendance?.check_in) {
    return null;
  }
  if (!attendance.check_out) {
    return `Masuk ${formatTime(attendance.check_in)}${attendance.is_missing_check_out ? ' · Belum absen pulang' : ''}`;
  }
  return `Masuk ${formatTime(attendance.check_in)} · Pulang ${formatTime(attendance.check_out)}`;
}

/** "Kerja 7 jam 30 menit · lembur 30 menit · telat 5 menit" for the day so far; null before any work or on an older server. */
export function dayTotalsLine(totals: DayTotals | null | undefined): string | null {
  if (!totals || (totals.worked_minutes <= 0 && totals.overtime_minutes <= 0 && totals.late_minutes <= 0)) {
    return null;
  }
  return [
    `Kerja ${formatMinutes(totals.worked_minutes)}`,
    totals.overtime_minutes > 0 ? `lembur ${formatMinutes(totals.overtime_minutes)}` : null,
    totals.late_minutes > 0 ? `telat ${formatMinutes(totals.late_minutes)}` : null,
  ]
    .filter(Boolean)
    .join(' · ');
}

/**
 * The date the card describes: the current session's business date, which is yesterday while last night's
 * closing shift is still open after midnight. Newer servers send it as session_date; older ones only give the
 * open attendance row's own date, and the calendar day otherwise.
 */
export function cardDate(today: Pick<TodayAttendance, 'date' | 'session_date' | 'attendance'>): string {
  return (today.session_date ?? today.attendance?.date ?? today.date).slice(0, 10);
}

/**
 * The worker has no schedule to work today ("Tidak ada jadwal hari ini"): only when a newer server says so,
 * and not on a rostered day off or a leave day, which the card already explains, nor once checked in.
 * The card describes the current session's business date, which is yesterday while last night's shift is
 * still on after midnight: the server's answer for that date (is_session_scheduled) wins over the one for
 * the calendar day, and a working session listed for the card's date always means there is a schedule.
 */
export function isUnscheduledToday(
  today: Pick<TodayAttendance, 'is_scheduled' | 'is_session_scheduled' | 'is_on_leave' | 'schedule' | 'has_checked_in' | 'schedules'>,
): boolean {
  const scheduled = today.is_session_scheduled ?? today.is_scheduled;
  return scheduled === false && workingScheduleCount(today) === 0 && today.is_on_leave !== true && today.schedule?.is_day_off !== true && !today.has_checked_in;
}
