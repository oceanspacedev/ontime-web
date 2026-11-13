import type { RosterEntry, RosterState, TeamRecapRow, TeamRoster, TeamShift, WeekCell } from './api/types';
import { toDateString, type Tone } from './format';

const STATE_LABELS: Record<RosterState, string> = {
  hadir: 'Hadir',
  terlambat: 'Terlambat',
  pulang: 'Pulang',
  menunggu: 'Menunggu',
  belum: 'Belum absen',
  nanti: 'Belum mulai',
  libur: 'Libur',
};

const STATE_TONES: Record<RosterState, Tone> = {
  hadir: 'success',
  pulang: 'success',
  terlambat: 'warning',
  menunggu: 'warning',
  belum: 'danger',
  nanti: 'gray',
  libur: 'gray',
};

/**
 * Badge for one person on the roster. Future days have no attendance yet, so only a day off shows.
 */
export function rosterBadge(entry: Pick<RosterEntry, 'state'>, date: string, today: string = toDateString(new Date())): { label: string; tone: Tone } | null {
  if (date > today && entry.state !== 'libur') {
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

/** The date `days` away from `date` (YYYY-MM-DD), in local time. */
export function shiftDate(date: string, days: number): string {
  const [y, m, d] = date.split('-').map(Number);
  return toDateString(new Date(y, m - 1, d + days));
}

/** "3 dari 8 orang sudah absen" for today and earlier, "8 orang terjadwal" for later days. */
export function rosterSummary(roster: TeamRoster, today: string = toDateString(new Date())): string {
  const people = roster.outlets.reduce((sum, outlet) => sum + outlet.counts.scheduled, 0);
  const present = roster.outlets.reduce((sum, outlet) => sum + outlet.counts.hadir + outlet.counts.terlambat + outlet.counts.pulang, 0);
  if (people === 0) {
    return 'Belum ada jadwal';
  }
  return roster.date <= today ? `${present} dari ${people} orang sudah absen` : `${people} orang terjadwal`;
}

/** What needs a look today: people missing or late. Undefined when everyone is fine. */
export function teamAttention(roster: TeamRoster): string | undefined {
  const missing = roster.outlets.reduce((sum, outlet) => sum + outlet.counts.belum, 0);
  const late = roster.outlets.reduce((sum, outlet) => sum + outlet.counts.terlambat, 0);
  const parts = [missing > 0 ? `${missing} belum absen` : null, late > 0 ? `${late} terlambat` : null].filter(Boolean);
  return parts.length > 0 ? parts.join(' · ') : undefined;
}

/** "Masuk 18/20 · 2 tidak hadir · 3× telat · 152,5 jam". */
export function recapLine(row: TeamRecapRow): string {
  const hours = row.worked_hours.toLocaleString('id-ID', { maximumFractionDigits: 2 });
  return [
    `Masuk ${row.present_days}/${row.scheduled_days}`,
    row.absent_days > 0 ? `${row.absent_days} tidak hadir` : null,
    row.late_days > 0 ? `${row.late_days}× telat` : null,
    `${hours} jam`,
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
