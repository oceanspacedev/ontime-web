import type { RosterEntry, RosterState, TeamShift } from './api/types';
import { toDateString, type Tone } from './format';

const STATE_LABELS: Record<RosterState, string> = {
  hadir: 'Hadir',
  terlambat: 'Terlambat',
  pulang: 'Pulang',
  menunggu: 'Menunggu',
  belum: 'Belum absen',
  libur: 'Libur',
};

const STATE_TONES: Record<RosterState, Tone> = {
  hadir: 'success',
  pulang: 'success',
  terlambat: 'warning',
  menunggu: 'warning',
  belum: 'danger',
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

/** "P · 08:00–17:00", or the shift name when it has no code. */
export function shiftText(shift: Pick<TeamShift, 'code' | 'name' | 'start_time' | 'end_time'>): string {
  return `${shift.code || shift.name} · ${shift.start_time}–${shift.end_time}`;
}

/** The line under a person's name: their shift and section, or "Libur". */
export function rosterLine(entry: Pick<RosterEntry, 'shift' | 'section' | 'state'>): string {
  if (entry.state === 'libur') {
    return 'Libur';
  }
  const parts = [entry.shift ? shiftText(entry.shift) : 'Tanpa shift', entry.section].filter(Boolean);
  return parts.join(' · ');
}

/** The date `days` away from `date` (YYYY-MM-DD), in local time. */
export function shiftDate(date: string, days: number): string {
  const [y, m, d] = date.split('-').map(Number);
  return toDateString(new Date(y, m - 1, d + days));
}
