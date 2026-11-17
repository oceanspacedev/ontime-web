/// <reference types="jest" />
import { approvalReasonOf, buildCheckForm, isOffScheduleToday, NO_COVER_SHIFT_MESSAGE, offScheduleSelectionError } from '../api/attendance';
import type { CoverOptions, TodaySchedule } from '../api/types';

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
