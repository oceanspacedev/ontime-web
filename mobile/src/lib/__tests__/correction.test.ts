import { buildCorrectionForm } from '../api/requests';
import type { Attendance } from '../api/types';
import {
  CHECK_IN_REQUIRED_MESSAGE,
  correctionClientError,
  correctionTargets,
  initialCorrectionDate,
  isCompleteListing,
  ownAttendanceRows,
  REJECTED_ATTENDANCE_MESSAGE,
} from '../correction';

jest.mock('expo-location', () => ({}));
jest.mock('react-native', () => ({ Platform: { OS: 'android' }, Linking: {} }));

describe('initialCorrectionDate', () => {
  const today = new Date(2026, 10, 17, 9, 0);

  it('prefills the day the home card passes', () => {
    expect(initialCorrectionDate('2026-11-16', today)).toBe('2026-11-16');
  });

  it('accepts today', () => {
    expect(initialCorrectionDate('2026-11-17', today)).toBe('2026-11-17');
  });

  it('takes the first value of a repeated param', () => {
    expect(initialCorrectionDate(['2026-11-15', '2026-11-16'], today)).toBe('2026-11-15');
  });

  it('ignores a missing, empty or malformed param', () => {
    expect(initialCorrectionDate(undefined, today)).toBeNull();
    expect(initialCorrectionDate('', today)).toBeNull();
    expect(initialCorrectionDate('16/11/2026', today)).toBeNull();
  });

  it('ignores a date after today', () => {
    expect(initialCorrectionDate('2026-11-18', today)).toBeNull();
  });
});

describe('buildCorrectionForm', () => {
  const base = { date: '2026-11-16', corrected_check_in: '22:00', corrected_check_out: '06:10', reason: 'Lupa absen' };

  it('sends the next-day check-out and the picked session only when set', () => {
    const form = buildCorrectionForm({ ...base, check_out_next_day: true, attendance_id: 12, schedule_id: 41 });
    expect(form.get('check_out_next_day')).toBe('1');
    expect(form.get('attendance_id')).toBe('12');
    expect(form.get('schedule_id')).toBe('41');
    expect(form.get('corrected_check_out')).toBe('06:10');
  });

  it('keeps the old payload when nothing new is picked', () => {
    const form = buildCorrectionForm({ ...base, check_out_next_day: false, attendance_id: null, schedule_id: null });
    expect(form.get('check_out_next_day')).toBeNull();
    expect(form.get('attendance_id')).toBeNull();
    expect(form.get('schedule_id')).toBeNull();
    expect(form.get('date')).toBe('2026-11-16');
    expect(form.get('reason')).toBe('Lupa absen');
  });
});

describe('correctionTargets', () => {
  const row = (overrides: Partial<Attendance>) => ({ id: 1, check_in: '08:00:00', status: 'on_time', schedule_id: 41, schedule_label: 'Pagi · 08:00–12:00', ...overrides }) as Attendance;

  it('offers a choice only on a date with several attendances, in check-in order', () => {
    expect(correctionTargets([row({})])).toEqual([]);
    expect(correctionTargets(null)).toEqual([]);
    expect(correctionTargets([row({ id: 2, check_in: '17:00:00', schedule_id: 42, schedule_label: 'Malam · 17:00–22:00' }), row({})])).toEqual([
      { attendance_id: 1, schedule_id: 41, label: 'Pagi · 08:00–12:00 · masuk 08:00' },
      { attendance_id: 2, schedule_id: 42, label: 'Malam · 17:00–22:00 · masuk 17:00' },
    ]);
  });

  it('labels rows from an older server by number', () => {
    const older = { id: 3, check_in: null, status: 'absent' } as Attendance;
    expect(correctionTargets([row({}), older])[0]).toEqual({ attendance_id: 3, schedule_id: null, label: 'Jadwal 1 · belum masuk' });
  });
});

describe('correctionClientError', () => {
  const checkedIn = { id: 1, check_in: '08:00:00', status: 'on_time' as const };
  const notIn = { id: 2, check_in: null, status: 'absent' as const };
  const checkInRequired = { field: 'corrected_check_in', message: CHECK_IN_REQUIRED_MESSAGE };

  it('refuses a check-out-only correction where the session has no check-in', () => {
    expect(correctionClientError({ checkIn: null, checkOut: '17:00', rows: [], pickedAttendanceId: null })).toEqual(checkInRequired);
    expect(correctionClientError({ checkIn: null, checkOut: '17:00', rows: [notIn], pickedAttendanceId: null })).toEqual(checkInRequired);
    expect(correctionClientError({ checkIn: null, checkOut: '17:00', rows: [checkedIn, notIn], pickedAttendanceId: 2 })).toEqual(checkInRequired);
  });

  it("gives the server's rejected-attendance message under the date for a rejected session", () => {
    const rejected = { field: 'date', message: REJECTED_ATTENDANCE_MESSAGE };
    expect(correctionClientError({ checkIn: null, checkOut: '17:00', rows: [{ ...checkedIn, status: 'rejected' }], pickedAttendanceId: null })).toEqual(rejected);
    expect(correctionClientError({ checkIn: null, checkOut: '17:00', rows: [{ ...notIn, status: 'rejected' }], pickedAttendanceId: null })).toEqual(rejected);
    // With a check-in the server accepts a correction of a rejected day.
    expect(correctionClientError({ checkIn: '08:00', checkOut: '17:00', rows: [{ ...checkedIn, status: 'rejected' }], pickedAttendanceId: null })).toBeNull();
  });

  it('lets it through when the session was checked in, a check-in is given, or the phone cannot tell', () => {
    expect(correctionClientError({ checkIn: null, checkOut: '17:00', rows: [checkedIn], pickedAttendanceId: null })).toBeNull();
    expect(correctionClientError({ checkIn: '08:00', checkOut: '17:00', rows: [], pickedAttendanceId: null })).toBeNull();
    expect(correctionClientError({ checkIn: null, checkOut: '17:00', rows: null, pickedAttendanceId: null })).toBeNull();
    // Several sessions and none picked: the server infers it from the time.
    expect(correctionClientError({ checkIn: null, checkOut: '17:00', rows: [checkedIn, notIn], pickedAttendanceId: null })).toBeNull();
  });

  it('leaves it to the server when the listing may be missing own rows (an older server paging a whole team)', () => {
    // The coordinator's own row is on page 2 of a team listing: no own row on page 1 proves nothing.
    expect(correctionClientError({ checkIn: null, checkOut: '17:00', rows: [], pickedAttendanceId: null, complete: false })).toBeNull();
    // A lone own row on page 1 may not be the only one, so the session is not inferred from it either.
    expect(correctionClientError({ checkIn: null, checkOut: '17:00', rows: [notIn], pickedAttendanceId: null, complete: false })).toBeNull();
    // A row the worker picked is still checked.
    expect(correctionClientError({ checkIn: null, checkOut: '17:00', rows: [checkedIn, notIn], pickedAttendanceId: 2, complete: false })).toEqual(checkInRequired);
    expect(correctionClientError({ checkIn: null, checkOut: '17:00', rows: [], pickedAttendanceId: null, complete: true })).toEqual(checkInRequired);
  });
});

describe('isCompleteListing', () => {
  it('is complete only when the listing fits one page', () => {
    expect(isCompleteListing({ last_page: 1 })).toBe(true);
    // No rows at all: Laravel answers last_page 1.
    expect(isCompleteListing({ last_page: 0 })).toBe(true);
    expect(isCompleteListing({ last_page: 3 })).toBe(false);
    expect(isCompleteListing(undefined)).toBe(false);
    expect(isCompleteListing(null)).toBe(false);
  });
});

describe('ownAttendanceRows', () => {
  const row = (overrides: Partial<Attendance>) =>
    ({ id: 1, employee_id: null, intern_id: null, freelancer_id: null, check_in: '08:00:00', status: 'on_time', schedule_id: 41, ...overrides }) as Attendance;
  const coordinator = { employee: { id: 7 }, intern: null, freelancer: null } as unknown as Parameters<typeof ownAttendanceRows>[1];

  it("keeps only the signed-in worker's rows from a team-scoped list", () => {
    const mine = row({ id: 10, employee_id: 7, check_in: '13:00:00', schedule_id: 42 });
    const colleagueA = row({ id: 11, employee_id: 8 });
    const colleagueB = row({ id: 12, employee_id: 9, check_in: '17:00:00', schedule_id: 43 });
    const sameIdOtherKind = row({ id: 13, freelancer_id: 7 });
    const own = ownAttendanceRows([colleagueA, mine, colleagueB, sameIdOtherKind], coordinator);
    expect(own).toEqual([mine]);
    // One own session left: no picker of colleagues' rows, and the check-out-only check reads the own row.
    expect(correctionTargets(own)).toEqual([]);
    expect(correctionClientError({ checkIn: null, checkOut: '17:00', rows: own, pickedAttendanceId: null })).toBeNull();
    // Judged on team data, a colleague's unchecked row would have been read as the worker's.
    expect(correctionTargets([colleagueA, mine, colleagueB])).toHaveLength(3);
  });

  it('matches interns and freelancers by their own profile, and nothing without a profile', () => {
    const intern = row({ id: 20, intern_id: 3 });
    const freelancer = row({ id: 21, freelancer_id: 3 });
    expect(ownAttendanceRows([intern, freelancer], { employee: null, intern: { id: 3 }, freelancer: null } as unknown as Parameters<typeof ownAttendanceRows>[1])).toEqual([intern]);
    expect(ownAttendanceRows([intern, freelancer], { employee: null, intern: null, freelancer: { id: 3 } } as unknown as Parameters<typeof ownAttendanceRows>[1])).toEqual([freelancer]);
    expect(ownAttendanceRows([intern, freelancer], null)).toEqual([]);
    expect(ownAttendanceRows(null, coordinator)).toBeNull();
  });
});
