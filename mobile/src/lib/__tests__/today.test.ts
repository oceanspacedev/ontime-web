import { formatDate } from '../format';
import { msUntilNextDay, unclosedNotice } from '../today';

describe('msUntilNextDay', () => {
  it('waits until just after the next local midnight', () => {
    expect(msUntilNextDay(new Date(2026, 10, 17, 23, 59, 0))).toBe(61 * 1000);
    expect(msUntilNextDay(new Date(2026, 10, 30, 12, 0, 0))).toBe(12 * 3600 * 1000 + 1000);
  });

  it('never returns less than a second, even right at midnight', () => {
    expect(msUntilNextDay(new Date(2026, 10, 17, 23, 59, 59, 999))).toBeGreaterThanOrEqual(1000);
  });
});

describe('unclosedNotice', () => {
  const nov16 = formatDate('2026-11-16', 'short');

  it('tells the worker yesterday was not checked out, names the date and offers a correction', () => {
    expect(unclosedNotice({ date: '2026-11-16', check_in: '15:00', shift: 'Sore', correction_pending: false }, '2026-11-17')).toEqual({
      message: `Kemarin (${nov16}) belum absen pulang — ajukan koreksi dengan jam pulang yang sebenarnya. Shift Sore, masuk 15:00. Absen masuk hari ini tetap bisa.`,
      canRequestCorrection: true,
    });
  });

  it('names an older date when days off came in between', () => {
    expect(unclosedNotice({ date: '2026-11-14', check_in: '15:00', shift: null, correction_pending: false }, '2026-11-16')?.message).toBe(
      `Tanggal ${formatDate('2026-11-14', 'short')} belum absen pulang — ajukan koreksi dengan jam pulang yang sebenarnya. Masuk 15:00. Absen masuk hari ini tetap bisa.`,
    );
  });

  it('does not offer a second correction while one is waiting', () => {
    expect(unclosedNotice({ date: '2026-11-16', check_in: '15:00', shift: null, correction_pending: true }, '2026-11-17')).toEqual({
      message: `Koreksi absen pulang kemarin (${nov16}) · masuk 15:00 sedang menunggu persetujuan.`,
      canRequestCorrection: false,
    });
  });

  it('shows nothing when nothing is open or the server is older', () => {
    expect(unclosedNotice(null)).toBeNull();
    expect(unclosedNotice(undefined)).toBeNull();
  });
});
