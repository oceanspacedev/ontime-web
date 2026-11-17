import type { PaginationB, RosterEntry, RosterOutlet, RosterState, TeamRecapRow, TeamRoster, TeamShift, WeekCell } from './api/types';
import { toDateString, type Tone } from './format';

const STATE_LABELS: Record<RosterState, string> = {
  hadir: 'Hadir',
  terlambat: 'Terlambat',
  pulang: 'Pulang',
  menunggu: 'Menunggu',
  belum: 'Belum absen',
  nanti: 'Belum mulai',
  libur: 'Libur',
  cuti: 'Cuti',
  jeda: 'Jeda',
  ditolak: 'Ditolak',
};

const STATE_TONES: Record<RosterState, Tone> = {
  hadir: 'success',
  pulang: 'success',
  terlambat: 'warning',
  menunggu: 'warning',
  belum: 'danger',
  nanti: 'gray',
  libur: 'gray',
  cuti: 'info',
  jeda: 'info',
  ditolak: 'danger',
};

/** Why a worker lent in from another brand cannot be changed here. */
export const BORROWED_READ_ONLY = 'Pekerja ini dipinjam dari brand lain. Jadwalnya diatur koordinator brand asalnya.';

/**
 * Badge for one person on the roster. Future days have no attendance yet, so only a day off or leave shows.
 */
export function rosterBadge(entry: Pick<RosterEntry, 'state'>, date: string, today: string = toDateString(new Date())): { label: string; tone: Tone } | null {
  if (date > today && entry.state !== 'libur' && entry.state !== 'cuti') {
    return null;
  }
  return { label: STATE_LABELS[entry.state], tone: STATE_TONES[entry.state] };
}

/** "08:00–17:00", or "07:45–13:00 & 17:45–22:00" for a split shift. */
export function hoursText(shift: Pick<TeamShift, 'start_time' | 'end_time' | 'segments'>): string {
  const segments = shift.segments?.length ? shift.segments : [{ start: shift.start_time, end: shift.end_time }];
  return segments.map((segment) => `${segment.start}–${segment.end}`).join(' & ');
}

/** "P · 08:00–17:00", or the shift name when it has no code. */
export function shiftText(shift: Pick<TeamShift, 'code' | 'name' | 'start_time' | 'end_time' | 'segments'>): string {
  return `${shift.code || shift.name} · ${hoursText(shift)}`;
}

/** The line under a person's name: their shift and section, or "Libur". */
export function rosterLine(entry: Pick<RosterEntry, 'shift' | 'section' | 'state'> & { borrowed_from?: string | null }): string {
  if (entry.state === 'libur') {
    return 'Libur';
  }
  const parts = [entry.shift ? shiftText(entry.shift) : 'Tanpa shift', entry.section, entry.borrowed_from ? `pinjam dari ${entry.borrowed_from}` : null].filter(Boolean);
  return parts.join(' · ');
}

/** Whether the coordinator may change this person's schedule; older servers send no flag. */
export function canEditSchedule(entry: { can_edit?: boolean }): boolean {
  return entry.can_edit !== false;
}

/** The page after this one, or undefined on the last page. */
export function nextPage(pagination: Pick<PaginationB, 'current_page' | 'last_page'>): number | undefined {
  return pagination.current_page < pagination.last_page ? pagination.current_page + 1 : undefined;
}

/** The date `days` away from `date` (YYYY-MM-DD), in local time. */
export function shiftDate(date: string, days: number): string {
  const [y, m, d] = date.split('-').map(Number);
  return toDateString(new Date(y, m - 1, d + days));
}

/**
 * The date the day view shows once the calendar moves on: when it was showing the old today it follows
 * to the new today (however many days passed in the background); a date picked by hand stays.
 */
export function followToday(shown: string, previousToday: string, nextToday: string): string {
  return shown === previousToday ? nextToday : shown;
}

/**
 * Scheduled people of a group who checked in and are at work or done: on time, late, done, waiting for
 * approval, between split segments. Someone who missed segment 2 of a split shift is "belum" only, so
 * they are not also counted here. Older servers send no `checked_in`.
 */
function checkedIn(outlet: RosterOutlet): number {
  const { counts } = outlet;
  return counts.checked_in ?? counts.hadir + counts.terlambat + counts.pulang + counts.menunggu + (counts.jeda ?? 0);
}

/**
 * "3 dari 8 orang sudah absen" for today and earlier, "8 orang terjadwal" for later days, followed by
 * who else is there: "· 1 di luar roster", "· 1 dari shift semalam".
 */
export function rosterSummary(roster: TeamRoster, today: string = toDateString(new Date())): string {
  const scheduled = roster.outlets.filter((outlet) => !outlet.kind);
  const people = scheduled.reduce((sum, outlet) => sum + outlet.counts.scheduled, 0);
  const present = scheduled.reduce((sum, outlet) => sum + checkedIn(outlet), 0);
  const extra = (kind: RosterOutlet['kind']) => roster.outlets.filter((outlet) => outlet.kind === kind).reduce((sum, outlet) => sum + outlet.entries.length, 0);
  const others = [extra('unscheduled') > 0 ? `${extra('unscheduled')} di luar roster` : null, extra('overnight') > 0 ? `${extra('overnight')} dari shift semalam` : null].filter(
    (part): part is string => part !== null,
  );
  if (people === 0) {
    return ['Belum ada jadwal', ...others].join(' · ');
  }
  return [roster.date <= today ? `${present} dari ${people} orang sudah absen` : `${people} orang terjadwal`, ...others].join(' · ');
}

/** A group's title: its outlet, "Di luar roster" or "Shift semalam" for people off the schedule, "Libur" for days off only. */
export function outletTitle(outlet: Pick<RosterOutlet, 'location' | 'kind' | 'entries'>): string {
  if (outlet.kind === 'unscheduled') {
    return 'Di luar roster';
  }
  if (outlet.kind === 'overnight') {
    return 'Shift semalam';
  }
  if (outlet.location) {
    return outlet.location.name;
  }
  return outlet.entries.every((entry) => entry.state === 'libur') ? 'Libur' : 'Tanpa outlet';
}

/** A stable list key: someone off the schedule has no schedule id. */
export function rosterEntryKey(entry: Pick<RosterEntry, 'schedule_id' | 'worker'>): string {
  return `${entry.worker.type}:${entry.worker.id}:${entry.schedule_id ?? 'tanpa-jadwal'}`;
}

/** The line under a name on the day view: shift and section, check-in time, and the server's note. */
export function rosterSubtitle(entry: Pick<RosterEntry, 'shift' | 'section' | 'state' | 'check_in' | 'note'> & { borrowed_from?: string | null }): string | undefined {
  if (entry.state === 'libur') {
    return entry.note ? `${entry.note}${entry.check_in ? ` · masuk ${entry.check_in}` : ''}` : undefined;
  }
  const line = entry.note === 'Tanpa jadwal' && !entry.shift ? null : rosterLine(entry);
  return [line, entry.check_in ? `masuk ${entry.check_in}` : null, entry.note].filter(Boolean).join(' · ');
}

/** What needs a look today: people missing or late. Undefined when everyone is fine. */
export function teamAttention(roster: TeamRoster): string | undefined {
  const missing = roster.outlets.reduce((sum, outlet) => sum + outlet.counts.belum, 0);
  const late = roster.outlets.reduce((sum, outlet) => sum + outlet.counts.terlambat, 0);
  const parts = [missing > 0 ? `${missing} belum absen` : null, late > 0 ? `${late} terlambat` : null].filter(Boolean);
  return parts.length > 0 ? parts.join(' · ') : undefined;
}

/** "Masuk 18/20 · 2 tidak hadir · 3× telat · 1× pulang cepat · 152,5 jam · lembur 6 jam". */
export function recapLine(row: TeamRecapRow): string {
  const hours = row.worked_hours.toLocaleString('id-ID', { maximumFractionDigits: 2 });
  const overtimeHours = row.overtime_hours ?? 0;
  return [
    `Masuk ${row.present_days}/${row.scheduled_days}`,
    row.absent_days > 0 ? `${row.absent_days} tidak hadir` : null,
    (row.pending_days ?? 0) > 0 ? `${row.pending_days} menunggu persetujuan` : null,
    row.late_days > 0 ? `${row.late_days}× telat` : null,
    (row.early_leave_days ?? 0) > 0 ? `${row.early_leave_days}× pulang cepat` : null,
    `${hours} jam`,
    overtimeHours > 0 ? `lembur ${overtimeHours.toLocaleString('id-ID', { maximumFractionDigits: 2 })} jam` : null,
  ]
    .filter(Boolean)
    .join(' · ');
}

/** The Monday of the week holding `date` (YYYY-MM-DD). */
export function weekStart(date: string): string {
  const [y, m, d] = date.split('-').map(Number);
  const day = new Date(y, m - 1, d).getDay();
  return shiftDate(date, day === 0 ? -6 : 1 - day);
}

/** What one cell of the weekly grid says: the shift code ("SP"), "OFF", or "–" when nothing is scheduled. */
export function weekCellText(cell: Pick<WeekCell, 'is_day_off' | 'shift'> | undefined): string {
  if (!cell) {
    return '–';
  }
  if (cell.is_day_off) {
    return 'OFF';
  }
  return cell.shift ? cell.shift.code || cell.shift.name : '–';
}
