import {
  crossesMidnight,
  earliestOvertimeDate,
  latestOvertimeDate,
  overtimeDateError,
  overtimeDurationMinutes,
  overtimeTimeError,
} from '../overtime';

describe('overtime duration', () => {
  it('counts a same-day range', () => {
    expect(overtimeDurationMinutes('17:00', '20:00')).toBe(180);
  });

  it('runs past midnight when the end is before the start', () => {
    expect(overtimeDurationMinutes('22:00', '02:00')).toBe(240);
    expect(crossesMidnight('22:00', '02:00')).toBe(true);
    expect(crossesMidnight('17:00', '20:00')).toBe(false);
  });

  it('is null for missing, unreadable or equal times', () => {
    expect(overtimeDurationMinutes(null, '20:00')).toBeNull();
    expect(overtimeDurationMinutes('25:00', '20:00')).toBeNull();
    expect(overtimeDurationMinutes('17:00', '17:00')).toBeNull();
  });
});

describe('overtime time error', () => {
  it('refuses equal times', () => {
    expect(overtimeTimeError('17:00', '17:00')).toBe('Jam selesai tidak boleh sama dengan jam mulai.');
  });

  it('allows exactly 12 hours and refuses more', () => {
    expect(overtimeTimeError('20:00', '08:00')).toBeNull();
    expect(overtimeTimeError('20:00', '08:01')).toBe('Durasi lembur maksimal 12 jam.');
    expect(overtimeTimeError('17:00', '16:59')).toBe('Durasi lembur maksimal 12 jam.');
  });

  it('says nothing while a time is still empty', () => {
    expect(overtimeTimeError('17:00', null)).toBeNull();
  });
});

describe('overtime date window', () => {
  const today = new Date(2026, 10, 16, 20, 0);

  it('allows today and up to 31 days back or ahead', () => {
    expect(overtimeDateError('2026-11-16', today)).toBeNull();
    expect(overtimeDateError('2026-10-16', today)).toBeNull();
    expect(overtimeDateError('2026-12-17', today)).toBeNull();
  });

  it('refuses dates further away', () => {
    expect(overtimeDateError('2026-10-15', today)).toBe('Tanggal lembur paling lama 31 hari ke belakang.');
    expect(overtimeDateError('2026-12-18', today)).toBe('Tanggal lembur paling jauh 31 hari ke depan.');
  });

  it('starts the picker 31 days back', () => {
    expect(earliestOvertimeDate(today)).toEqual(new Date(2026, 9, 16));
  });

  it('ends the picker 31 days ahead', () => {
    expect(latestOvertimeDate(today)).toEqual(new Date(2026, 11, 17));
  });
});
