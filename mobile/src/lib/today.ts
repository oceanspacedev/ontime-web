import type { UnclosedPrevious } from './api/types';
import { formatDate, formatTime, toDateString } from './format';

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
