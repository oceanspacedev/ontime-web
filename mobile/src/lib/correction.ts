import { toDateString } from '@/lib/format';

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
