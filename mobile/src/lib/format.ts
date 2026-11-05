import type { AttendanceStatus, RequestStatus } from './api/types';

const LOCALE = 'id-ID';

function parseDate(value: string | null | undefined): Date | null {
  if (!value) {
    return null;
  }
  // Plain dates (YYYY-MM-DD) are local calendar days, never shifted by timezone.
  const plain = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value);
  if (plain) {
    return new Date(Number(plain[1]), Number(plain[2]) - 1, Number(plain[3]));
  }
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? null : date;
}

export function toDateString(date: Date): string {
  const y = date.getFullYear();
  const m = String(date.getMonth() + 1).padStart(2, '0');
  const d = String(date.getDate()).padStart(2, '0');
  return `${y}-${m}-${d}`;
}

export function toTimeString(date: Date): string {
  return `${String(date.getHours()).padStart(2, '0')}:${String(date.getMinutes()).padStart(2, '0')}`;
}

export function formatDate(value: string | null | undefined, style: 'long' | 'short' | 'weekday' = 'long'): string {
  const date = parseDate(value);
  if (!date) {
    return '-';
  }
  if (style === 'short') {
    return date.toLocaleDateString(LOCALE, { day: 'numeric', month: 'short', year: 'numeric' });
  }
  if (style === 'weekday') {
    return date.toLocaleDateString(LOCALE, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
  }
  return date.toLocaleDateString(LOCALE, { day: 'numeric', month: 'long', year: 'numeric' });
}

export function formatDateRange(start: string | null | undefined, end: string | null | undefined): string {
  if (start && end && start !== end) {
    return `${formatDate(start, 'short')} – ${formatDate(end, 'short')}`;
  }
  return formatDate(start ?? end, 'short');
}

/** Times arrive either as ISO datetimes or bare `HH:MM[:SS]` strings. */
export function formatTime(value: string | null | undefined): string {
  if (!value) {
    return '-';
  }
  const bare = /^(\d{2}):(\d{2})(?::\d{2})?$/.exec(value);
  if (bare) {
    return `${bare[1]}:${bare[2]}`;
  }
  const date = parseDate(value);
  if (!date) {
    return value;
  }
  return date.toLocaleTimeString(LOCALE, { hour: '2-digit', minute: '2-digit', hour12: false }).replace('.', ':');
}

export function formatDateTime(value: string | null | undefined): string {
  const date = parseDate(value);
  if (!date) {
    return '-';
  }
  return `${formatDate(value, 'short')} ${formatTime(value)}`;
}

export function formatMinutes(minutes: number | null | undefined): string {
  if (!minutes) {
    return '0 menit';
  }
  const hours = Math.floor(minutes / 60);
  const rest = minutes % 60;
  if (hours === 0) {
    return `${rest} menit`;
  }
  return rest === 0 ? `${hours} jam` : `${hours} jam ${rest} menit`;
}

export type Tone = 'success' | 'warning' | 'danger' | 'info' | 'gray';

export function attendanceStatusLabel(status: AttendanceStatus | string): string {
  switch (status) {
    case 'on_time':
      return 'Tepat Waktu';
    case 'late':
      return 'Terlambat';
    case 'absent':
      return 'Tidak Hadir';
    case 'leave':
      return 'Cuti / Izin';
    case 'holiday':
      return 'Hari Libur';
    case 'off':
      return 'Libur';
    case 'pending_approval':
      return 'Menunggu Persetujuan';
    case 'rejected':
      return 'Ditolak';
    default:
      return status;
  }
}

export function attendanceStatusTone(status: AttendanceStatus | string): Tone {
  switch (status) {
    case 'on_time':
      return 'success';
    case 'late':
    case 'pending_approval':
      return 'warning';
    case 'absent':
    case 'rejected':
      return 'danger';
    case 'leave':
      return 'info';
    default:
      return 'gray';
  }
}

export function requestStatusLabel(status: RequestStatus | string): string {
  switch (status) {
    case 'pending':
      return 'Menunggu Persetujuan';
    case 'approved':
      return 'Disetujui';
    case 'rejected':
      return 'Ditolak';
    default:
      return status;
  }
}

export function requestStatusTone(status: RequestStatus | string): Tone {
  switch (status) {
    case 'pending':
      return 'warning';
    case 'approved':
      return 'success';
    case 'rejected':
      return 'danger';
    default:
      return 'gray';
  }
}

export function greeting(now = new Date()): string {
  const hour = now.getHours();
  if (hour < 11) {
    return 'Selamat pagi';
  }
  if (hour < 15) {
    return 'Selamat siang';
  }
  if (hour < 18) {
    return 'Selamat sore';
  }
  return 'Selamat malam';
}
