/// <reference types="jest" />
import { approvalReasonOf, buildCheckForm, coverLocationOf, currentScheduleId, freshCurrentScheduleId, isOffScheduleToday, NO_COVER_SHIFT_MESSAGE, offScheduleSelectionError } from '../api/attendance';
import type { CoverOptions, TodaySchedule, TodayScheduleItem } from '../api/types';

jest.mock('expo-location', () => ({}));
jest.mock('react-native', () => ({ Platform: { OS: 'android' }, Linking: {} }));

const options: CoverOptions = {
  date: '2026-11-16',
  is_off_schedule: true,
  off_schedule_reason: 'no_schedule',
  shifts: [{ id: 3, name: 'Pagi', start_time: '08:00', end_time: '16:00' }],
  colleagues: [
    { key: 'employee:12', name: 'Budi', type: 'employee', shift: { id: 3, name: 'Pagi', start_time: '08:00', end_time: '16:00' }, outlet: null, has_checked_in: false },
  ],
};

function schedule(overrides: Partial<TodaySchedule>): TodaySchedule {
  return { shift: null, is_day_off: false, start_time: '08:00', end_time: '17:00', late_tolerance_minutes: 15, ...overrides };
}

describe('isOffScheduleToday', () => {
  it('follows the cover options when the server sends them', () => {
    expect(isOffScheduleToday(options, schedule({}), false)).toBe(true);
    expect(isOffScheduleToday({ ...options, is_off_schedule: false }, schedule({ is_day_off: true }), true)).toBe(false);
  });

  it('falls back to the schedule flag, then to a day off or a freelancer without a shift', () => {
    expect(isOffScheduleToday(null, schedule({ is_off_schedule: true }), false)).toBe(true);
    expect(isOffScheduleToday(null, schedule({ is_day_off: true }), false)).toBe(true);
    expect(isOffScheduleToday(null, schedule({}), true)).toBe(true);
    expect(isOffScheduleToday(null, schedule({}), false)).toBe(false);
    expect(isOffScheduleToday(null, schedule({ shift: { id: 3, name: 'Pagi' } }), true)).toBe(false);
    expect(isOffScheduleToday(null, null, true)).toBe(false);
  });
});

describe('offScheduleSelectionError', () => {
  it('requires the covered shift from every worker type', () => {
    expect(offScheduleSelectionError(options, { coverShiftId: null, replacesWorker: null })).toBe('Pilih shift yang Anda gantikan.');
    expect(offScheduleSelectionError(options, { coverShiftId: 3, replacesWorker: null })).toBeNull();
    expect(offScheduleSelectionError(options, { coverShiftId: 3, replacesWorker: 'employee:12' })).toBeNull();
  });

  it('refuses a shift or colleague that is no longer on offer', () => {
    expect(offScheduleSelectionError(options, { coverShiftId: 99, replacesWorker: null })).toBe('Shift yang dipilih sudah tidak tersedia. Pilih shift lain.');
    expect(offScheduleSelectionError(options, { coverShiftId: 3, replacesWorker: 'employee:99' })).toBe('Rekan yang dipilih sudah tidak terjadwal hari ini. Pilih rekan lain.');
  });

  it('tells the worker to ask the PIC when the company has no active shift', () => {
    expect(offScheduleSelectionError({ ...options, shifts: [] }, { coverShiftId: null, replacesWorker: null })).toBe(NO_COVER_SHIFT_MESSAGE);
  });

  it('asks nothing before the options are loaded (the server still refuses a missing shift)', () => {
    expect(offScheduleSelectionError(null, { coverShiftId: null, replacesWorker: null })).toBeNull();
  });
});

describe('approvalReasonOf', () => {
  it('shows the server label of an off-schedule check-in', () => {
    expect(approvalReasonOf({ is_off_schedule: true, off_schedule_label: 'Masuk di luar jadwal · menggantikan Budi · shift Closing', replaced_worker: { key: 'employee:12', name: 'Budi' } }))
      .toMatch(/^Masuk di luar jadwal · menggantikan Budi · shift Closing\. /);
  });

  it('builds the label from the flags when the server sends none', () => {
    expect(approvalReasonOf({ is_off_schedule: true, replaced_worker: { key: 'employee:12', name: 'Budi' } })).toMatch(/^Masuk di luar jadwal · menggantikan Budi\. /);
    expect(approvalReasonOf({ is_off_schedule: true })).toMatch(/^Masuk di luar jadwal\. /);
  });

  it('keeps the geofence notice for a rostered check-in', () => {
    expect(approvalReasonOf({ is_off_schedule: false })).toMatch(/di luar area kantor/);
    expect(approvalReasonOf({})).toMatch(/di luar area kantor/);
  });
});

describe('buildCheckForm', () => {
  it('sends the cover shift and the replaced colleague only when picked', () => {
    const picked = buildCheckForm({ coverShiftId: 3, replacesWorker: 'employee:12' });
    expect(picked.get('cover_shift_id')).toBe('3');
    expect(picked.get('replaces_worker')).toBe('employee:12');

    const plain = buildCheckForm({ coverShiftId: null, replacesWorker: null });
    expect(plain.get('cover_shift_id')).toBeNull();
    expect(plain.get('replaces_worker')).toBeNull();
  });
});

describe('buildCheckForm with sessions and a cover outlet', () => {
  it('sends the schedule and the cover outlet only when present', () => {
    const named = buildCheckForm({ scheduleId: 41, coverLocationId: 7 });
    expect(named.get('schedule_id')).toBe('41');
    expect(named.get('cover_location_id')).toBe('7');

    for (const plain of [buildCheckForm({}), buildCheckForm({ scheduleId: null, coverLocationId: null })]) {
      expect(plain.get('schedule_id')).toBeNull();
      expect(plain.get('cover_location_id')).toBeNull();
    }
  });
});

describe('currentScheduleId', () => {
  function item(overrides: Partial<TodayScheduleItem>): TodayScheduleItem {
    return {
      schedule_id: 1,
      sequence: 1,
      segment: 1,
      is_current: false,
      is_day_off: false,
      shift: { id: 1, name: 'Pagi', code: 'P' },
      outlet: null,
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

  it('names the current session on a day with several schedules', () => {
    const schedules = [item({ schedule_id: 1, state: 'pulang' }), item({ schedule_id: 2, sequence: 2, is_current: true, start_time: '17:00', end_time: '22:00' })];
    expect(currentScheduleId({ schedules }, false)).toBe(2);
  });

  it('lets the server pick on a one-schedule day, on an older server, and off schedule', () => {
    expect(currentScheduleId({ schedules: [item({ is_current: true })] }, false)).toBeNull();
    expect(currentScheduleId({}, false)).toBeNull();
    expect(currentScheduleId(null, false)).toBeNull();
    const schedules = [item({ schedule_id: 1, is_current: true }), item({ schedule_id: 2, sequence: 2 })];
    expect(currentScheduleId({ schedules }, true)).toBeNull();
    // Standing company hours have no roster row to name.
    expect(currentScheduleId({ schedules: [item({ schedule_id: null, is_current: true }), item({ schedule_id: 2 })] }, false)).toBeNull();
  });

  it('lets the server pick on a split-shift day, whose segments are one schedule', () => {
    const split = [
      item({ schedule_id: 5, segment: 1, start_time: '07:45', end_time: '13:00', state: 'pulang' }),
      item({ schedule_id: 5, segment: 2, start_time: '17:45', end_time: '22:00', is_current: true }),
    ];
    expect(currentScheduleId({ schedules: split }, false)).toBeNull();
    // With a second schedule the split shift's current segment is named by its schedule.
    expect(currentScheduleId({ schedules: [...split, item({ schedule_id: 6, sequence: 2, start_time: '22:30', end_time: '23:30' })] }, false)).toBe(5);
  });

  it('names the session current on a fresh read, not the cached card', async () => {
    // The cached card (read at 11:59) still had session 1 current; by the submit at 12:00 it is session 2.
    const fresh = [item({ schedule_id: 1, state: 'belum' }), item({ schedule_id: 2, sequence: 2, is_current: true, start_time: '13:00', end_time: '17:00' })];
    const read = jest.fn(() => Promise.resolve({ schedules: fresh }));
    await expect(freshCurrentScheduleId(read)).resolves.toBe(2);
    expect(read).toHaveBeenCalledTimes(1);
  });

  it('lets the server pick when the fresh read fails', async () => {
    await expect(freshCurrentScheduleId(() => Promise.reject(new Error('offline')))).resolves.toBeNull();
  });
});

describe('coverLocationOf', () => {
  it('keeps a picked outlet only while it is on offer', () => {
    const withOutlets: CoverOptions = { ...options, outlets: [{ id: 7, name: 'JChicken Ciledug' }] };
    expect(coverLocationOf(withOutlets, 7)).toBe(7);
    expect(coverLocationOf(withOutlets, 8)).toBeNull();
    expect(coverLocationOf(withOutlets, null)).toBeNull();
    // An older server lists no outlets.
    expect(coverLocationOf(options, 7)).toBeNull();
  });
});
