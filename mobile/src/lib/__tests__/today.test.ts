import type { TodayAttendance, TodayScheduleItem } from '../api/types';
import { formatDate } from '../format';
import { cardDate, dayTotalsLine, isUnscheduledToday, listedSchedules, msUntilNextDay, scheduleItemAttendance, scheduleItemLine, unclosedNotice, workingScheduleCount } from '../today';

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

describe('the day with several schedules', () => {
  const older: TodayAttendance = {
    date: '2026-11-17',
    has_checked_in: false,
    has_checked_out: false,
    attendance: null,
    schedule: { shift: null, is_day_off: false, start_time: '08:00', end_time: '17:00', late_tolerance_minutes: 15 },
  };

  function item(overrides: Partial<TodayScheduleItem>): TodayScheduleItem {
    return {
      schedule_id: 1,
      sequence: 1,
      segment: 1,
      is_current: false,
      is_day_off: false,
      shift: { id: 1, name: 'Pagi', code: 'P' },
      outlet: { id: 3, name: 'JChicken Ciledug' },
      section: null,
      start_time: '08:00',
      end_time: '12:00',
      crosses_midnight: false,
      late_tolerance_minutes: 15,
      normal_minutes: 240,
      state: 'nanti',
      check_out_closes_at: null,
      attendance: null,
      ...overrides,
    };
  }

  it('shows the card as before when an older server sends no schedules', () => {
    expect(listedSchedules(older)).toEqual([]);
    expect(isUnscheduledToday(older)).toBe(false);
    expect(dayTotalsLine(older.day_totals)).toBeNull();
  });

  it('lists the schedules only when the day has more than one working schedule', () => {
    const one = [item({ is_current: true })];
    expect(listedSchedules({ schedules: one })).toEqual([]);
    expect(listedSchedules({ schedules: [item({ is_day_off: true, shift: null })] })).toEqual([]);
    const two = [item({ schedule_id: 1 }), item({ schedule_id: 2, sequence: 2, start_time: '17:00', end_time: '22:00', is_current: true })];
    expect(listedSchedules({ schedules: two })).toEqual(two);
  });

  it('counts a split shift as one schedule, though the server sends one item per segment', () => {
    const split = [
      item({ schedule_id: 5, segment: 1, start_time: '07:45', end_time: '13:00', is_current: true }),
      item({ schedule_id: 5, segment: 2, start_time: '17:45', end_time: '22:00' }),
    ];
    expect(workingScheduleCount({ schedules: split })).toBe(1);
    expect(listedSchedules({ schedules: split })).toEqual([]);
    // Standing company hours (no roster row) split in two are one schedule too.
    const standing = split.map((segment) => ({ ...segment, schedule_id: null }));
    expect(workingScheduleCount({ schedules: standing })).toBe(1);
    expect(listedSchedules({ schedules: standing })).toEqual([]);
    // A split shift plus another schedule: two schedules, every session listed.
    const withEvening = [...split, item({ schedule_id: 6, sequence: 2, start_time: '22:30', end_time: '23:30' })];
    expect(workingScheduleCount({ schedules: withEvening })).toBe(2);
    expect(listedSchedules({ schedules: withEvening })).toEqual(withEvening);
    expect(workingScheduleCount(older)).toBe(0);
  });

  it('describes one schedule and its attendance', () => {
    expect(scheduleItemLine(item({}))).toBe('Pagi · 08:00–12:00 · JChicken Ciledug');
    expect(scheduleItemLine(item({ shift: null, outlet: null, section: 'Bar', start_time: '22:00', end_time: '06:00', crosses_midnight: true }))).toBe(
      'Jam kerja · 22:00–06:00 (s.d. besok) · Bar',
    );
    const attendance = { check_in: '08:02:00', check_out: null, is_missing_check_out: false } as unknown as TodayScheduleItem['attendance'];
    expect(scheduleItemAttendance(item({}))).toBeNull();
    expect(scheduleItemAttendance(item({ attendance }))).toBe('Masuk 08:02');
    expect(scheduleItemAttendance(item({ attendance: { ...attendance!, is_missing_check_out: true } }))).toBe('Masuk 08:02 · Belum absen pulang');
    expect(scheduleItemAttendance(item({ attendance: { ...attendance!, check_out: '12:05:00' } }))).toBe('Masuk 08:02 · Pulang 12:05');
  });

  it('says there is no schedule only when a newer server says so', () => {
    expect(isUnscheduledToday({ ...older, is_scheduled: false })).toBe(true);
    expect(isUnscheduledToday({ ...older, is_scheduled: true })).toBe(false);
    // A rostered day off and a leave day are explained by the card already; a check-in made it a work day.
    expect(isUnscheduledToday({ ...older, is_scheduled: false, schedule: { ...older.schedule!, is_day_off: true } })).toBe(false);
    expect(isUnscheduledToday({ ...older, is_scheduled: false, is_on_leave: true })).toBe(false);
    expect(isUnscheduledToday({ ...older, is_scheduled: false, has_checked_in: true })).toBe(false);
  });

  it('judges the business date the card shows, not the calendar day, after midnight', () => {
    // 00:30: last night's 22:00–06:00 is the current session, today has nothing.
    const night = item({ start_time: '22:00', end_time: '06:00', crosses_midnight: true, is_current: true });
    expect(isUnscheduledToday({ ...older, is_scheduled: false, is_session_scheduled: true, schedules: [night] })).toBe(false);
    // A server that sends only is_scheduled (for today) still lists that session: it is not "no schedule".
    expect(isUnscheduledToday({ ...older, is_scheduled: false, schedules: [night] })).toBe(false);
    // The card's date has nothing to work, although the calendar day has: the notice follows the card's date.
    expect(isUnscheduledToday({ ...older, is_scheduled: true, is_session_scheduled: false, schedules: [] })).toBe(true);
  });

  it('dates the card by the session it shows, which is yesterday while last night\'s shift is open', () => {
    const open = { id: 1, date: '2026-11-18' } as unknown as TodayAttendance['attendance'];
    expect(cardDate({ date: '2026-11-19', session_date: '2026-11-18', attendance: open })).toBe('2026-11-18');
    // An older server sends no session_date: the open row's own date still names the shift's day.
    expect(cardDate({ date: '2026-11-19', attendance: open })).toBe('2026-11-18');
    expect(cardDate({ date: '2026-11-19', attendance: null })).toBe('2026-11-19');
  });

  it('adds up the day', () => {
    expect(dayTotalsLine({ worked_minutes: 0, overtime_minutes: 0, late_minutes: 0 })).toBeNull();
    expect(dayTotalsLine({ worked_minutes: 450, overtime_minutes: 30, late_minutes: 5 })).toBe('Kerja 7 jam 30 menit · lembur 30 menit · telat 5 menit');
    expect(dayTotalsLine({ worked_minutes: 240, overtime_minutes: 0, late_minutes: 0 })).toBe('Kerja 4 jam');
  });
});
