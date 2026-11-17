/// <reference types="jest" />
/**
 * Chaos lens "F&B real-world combinations" (client side): a backup check-in picked from cover options that
 * no longer match the day (a swap moved the colleague to another shift, the colleague already came in).
 */
import { buildCheckForm, offScheduleSelectionError } from '../api/attendance';
import type { CoverOptions } from '../api/types';

jest.mock('expo-location', () => ({}));
jest.mock('react-native', () => ({ Platform: { OS: 'android' }, Linking: {} }));

const pagi = { id: 3, name: 'Pagi', start_time: '08:00', end_time: '16:00' };
const sore = { id: 4, name: 'Sore', start_time: '16:00', end_time: '23:00' };

/** After an approved swap Andi works Sore and Citra works Pagi. */
const afterSwap: CoverOptions = {
  date: '2026-11-17',
  is_off_schedule: true,
  off_schedule_reason: 'no_schedule',
  shifts: [pagi, sore],
  colleagues: [
    { key: 'employee:1', name: 'Andi', type: 'employee', shift: sore, outlet: { id: 1, name: 'Outlet Mall' }, has_checked_in: false },
    { key: 'employee:2', name: 'Citra', type: 'employee', shift: pagi, outlet: { id: 1, name: 'Outlet Mall' }, has_checked_in: true },
  ],
};

describe('backup picked against a swapped roster', () => {
  it('refuses covering Pagi "menggantikan Andi" when Andi is rostered on Sore', () => {
    // The approval would give Andi's Sore shift the day off although nobody covers it.
    expect(offScheduleSelectionError(afterSwap, { coverShiftId: pagi.id, replacesWorker: 'employee:1' })).not.toBeNull();
  });

  it('accepts covering the colleague\'s own shift', () => {
    expect(offScheduleSelectionError(afterSwap, { coverShiftId: sore.id, replacesWorker: 'employee:1' })).toBeNull();
    expect(buildCheckForm({ coverShiftId: sore.id, replacesWorker: 'employee:1' }).get('cover_shift_id')).toBe('4');
  });

  it('still refuses a colleague no longer on offer after the roster changed', () => {
    expect(offScheduleSelectionError(afterSwap, { coverShiftId: pagi.id, replacesWorker: 'employee:99' }))
      .toBe('Rekan yang dipilih sudah tidak terjadwal hari ini. Pilih rekan lain.');
  });

  it('accepts a plain backup without a replaced colleague on any offered shift', () => {
    expect(offScheduleSelectionError(afterSwap, { coverShiftId: pagi.id, replacesWorker: null })).toBeNull();
  });
});
