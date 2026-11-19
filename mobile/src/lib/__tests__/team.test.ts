import { deriveAccess } from '../access';
import type { TeamRoster, User, WeekCell } from '../api/types';
import { daySchedules, entrySupportsMultipleSchedules, recapLine, rosterBadge, rosterLine, rosterSummary, shiftDate, shiftText, teamAttention, weekCellMarks, weekCellText, weekStart } from '../team';

function user(overrides: Partial<User>): User {
  return {
    id: 1,
    name: 'Loocita',
    email: 'loocita@example.test',
    master_face_photo: null,
    master_face_verified_at: null,
    roles: [],
    permissions: [],
    employee: null,
    intern: null,
    freelancer: null,
    ...overrides,
  };
}

describe('deriveAccess', () => {
  it('opens the Tim tab only when the server says the user runs a team', () => {
    expect(deriveAccess(user({ roles: ['Koordinator'], access: { can_manage_team: true, is_coordinator: true } })).canManageTeam).toBe(true);
    expect(deriveAccess(user({ roles: ['Employee'], access: { can_manage_team: false, is_coordinator: false } })).canManageTeam).toBe(false);
    expect(deriveAccess(user({ roles: ['Admin'] })).canManageTeam).toBe(false);
  });

  it('offers the Waste shortcut only when the server sends a link', () => {
    const url = 'https://cesa.example/waste';
    expect(deriveAccess(user({ roles: ['Freelancer'], access: { can_manage_team: false, is_coordinator: false, waste_url: url } })).wasteUrl).toBe(url);
    expect(deriveAccess(user({ roles: ['Admin'], access: { can_manage_team: true, is_coordinator: false, waste_url: null } })).wasteUrl).toBeNull();
    expect(deriveAccess(user({ roles: ['Employee'] })).wasteUrl).toBeNull();
  });

  it('lets a coordinator approve requests', () => {
    expect(deriveAccess(user({ roles: ['Koordinator'] })).canApprove).toBe(true);
  });
});

describe('roster helpers', () => {
  const shift = { code: 'P', name: 'Pagi', start_time: '08:00', end_time: '17:00' };

  it('writes a shift with its code or name', () => {
    expect(shiftText(shift)).toBe('P · 08:00–17:00');
    expect(shiftText({ ...shift, code: null })).toBe('Pagi · 08:00–17:00');
  });

  it('shows both segments of a split shift', () => {
    const split = { code: 'SP', name: 'Split', start_time: '07:45', end_time: '13:00', segments: [{ start: '07:45', end: '13:00' }, { start: '17:45', end: '22:00' }] };
    expect(shiftText(split)).toBe('SP · 07:45–13:00 & 17:45–22:00');
  });

  it('describes a person by shift and section, or day off', () => {
    expect(rosterLine({ shift: { id: 1, label: '', ...shift }, section: 'Kasir', state: 'hadir' })).toBe('P · 08:00–17:00 · Kasir');
    expect(rosterLine({ shift: null, section: null, state: 'libur' })).toBe('Libur');
  });

  it('shows attendance badges up to today, and only days off after', () => {
    expect(rosterBadge({ state: 'belum' }, '2026-11-06', '2026-11-06')).toEqual({ label: 'Belum absen', tone: 'danger' });
    expect(rosterBadge({ state: 'belum' }, '2026-11-07', '2026-11-06')).toBeNull();
    expect(rosterBadge({ state: 'libur' }, '2026-11-07', '2026-11-06')).toEqual({ label: 'Libur', tone: 'gray' });
    expect(rosterBadge({ state: 'nanti' }, '2026-11-06', '2026-11-06')).toEqual({ label: 'Belum mulai', tone: 'gray' });
  });

  it('moves a date across month ends', () => {
    expect(shiftDate('2026-11-30', 1)).toBe('2026-12-01');
    expect(shiftDate('2026-11-01', -1)).toBe('2026-10-31');
  });
});

describe('team summaries', () => {
  const counts = { scheduled: 4, hadir: 1, terlambat: 1, pulang: 0, menunggu: 0, belum: 1, nanti: 1, libur: 1 };
  const roster: TeamRoster = { date: '2026-11-06', outlets: [{ location: { id: 1, name: 'JChicken Ciledug' }, counts, entries: [] }] };

  it('counts who already checked in, or who is scheduled on later days', () => {
    expect(rosterSummary(roster, '2026-11-06')).toBe('2 dari 4 orang sudah absen');
    expect(rosterSummary({ ...roster, date: '2026-11-07' }, '2026-11-06')).toBe('4 orang terjadwal');
    expect(rosterSummary({ date: '2026-11-06', outlets: [] }, '2026-11-06')).toBe('Belum ada jadwal');
  });

  it('prefers the distinct people a newer server counts, so a two-outlet person counts once', () => {
    const twoOutlets: TeamRoster = {
      date: '2026-11-06',
      outlets: [
        { location: { id: 1, name: 'JChicken Ciledug' }, counts: { ...counts, scheduled: 2, hadir: 1, terlambat: 0 }, entries: [] },
        { location: { id: 2, name: 'JChicken Bintaro' }, counts: { ...counts, scheduled: 2, hadir: 1, terlambat: 0 }, entries: [] },
      ],
    };
    // An older server: per-outlet counts are added up.
    expect(rosterSummary(twoOutlets, '2026-11-06')).toBe('2 dari 4 orang sudah absen');
    expect(rosterSummary({ ...twoOutlets, summary: { people_scheduled: 3, people_checked_in: 1 } }, '2026-11-06')).toBe('1 dari 3 orang sudah absen');
    expect(rosterSummary({ ...twoOutlets, date: '2026-11-07', summary: { people_scheduled: 3, people_checked_in: 0 } }, '2026-11-06')).toBe('3 orang terjadwal');
    expect(rosterSummary({ date: '2026-11-06', outlets: [], summary: { people_scheduled: 0, people_checked_in: 0 } }, '2026-11-06')).toBe('Belum ada jadwal');
  });

  it('points out who is missing or late', () => {
    expect(teamAttention(roster)).toBe('1 belum absen · 1 terlambat');
    expect(teamAttention({ ...roster, outlets: [{ ...roster.outlets[0], counts: { ...counts, belum: 0, terlambat: 0 } }] })).toBeUndefined();
  });
});

describe('recapLine', () => {
  const row = {
    worker: { type: 'freelancer' as const, id: 1, number: 'FL-1', name: 'Bayu', company: null, division: null },
    outlet: 'JChicken Ciledug',
    scheduled_days: 20,
    present_days: 18,
    late_days: 3,
    late_minutes: 45,
    absent_days: 2,
    off_days: 4,
    scheduled_hours: 180,
    worked_hours: 152.5,
  };

  it('sums up a month in one line', () => {
    expect(recapLine(row)).toBe('Masuk 18/20 · 2 tidak hadir · 3× telat · 152,5 jam');
    expect(recapLine({ ...row, absent_days: 0, late_days: 0, worked_hours: 160 })).toBe('Masuk 18/20 · 160 jam');
    expect(recapLine({ ...row, early_leave_days: 1, overtime_hours: 6.5 })).toBe('Masuk 18/20 · 2 tidak hadir · 3× telat · 1× pulang cepat · 152,5 jam · lembur 6,5 jam');
    expect(recapLine({ ...row, pending_days: 2 })).toBe('Masuk 18/20 · 2 tidak hadir · 2 menunggu persetujuan · 3× telat · 152,5 jam');
    expect(recapLine({ ...row, early_leave_days: 0, overtime_hours: 0 })).toBe('Masuk 18/20 · 2 tidak hadir · 3× telat · 152,5 jam');
  });

  it('appends leave days and missed check-outs from a newer server', () => {
    expect(recapLine({ ...row, leave_days: 2 })).toBe('Masuk 18/20 · 2 tidak hadir · 3× telat · 152,5 jam · 2 hari cuti');
    expect(recapLine({ ...row, leave_days: 2, missing_check_out_days: 1, overtime_hours: 6 })).toBe(
      'Masuk 18/20 · 2 tidak hadir · 3× telat · 152,5 jam · lembur 6 jam · 2 hari cuti · 1× belum absen pulang',
    );
    expect(recapLine({ ...row, leave_days: 0, missing_check_out_days: 0 })).toBe('Masuk 18/20 · 2 tidak hadir · 3× telat · 152,5 jam');
  });
});

describe('pushTarget', () => {
  // eslint-disable-next-line @typescript-eslint/no-require-imports -- keeps the import local to this block
  const { pushTarget } = require('../push') as typeof import('../push');

  it('opens the check screen from an attendance reminder', () => {
    expect(pushTarget({ kind: 'attendance_reminder', action: 'check_in' }, false)).toEqual({ pathname: '/attendance/check', params: { mode: 'in' } });
    expect(pushTarget({ kind: 'attendance_reminder', action: 'check_out' }, false)).toEqual({ pathname: '/attendance/check', params: { mode: 'out' } });
  });

  it('opens the correction form on the shift date from a forgotten check-out reminder', () => {
    expect(pushTarget({ kind: 'attendance_reminder', action: 'correction', date: '2026-11-16' }, false)).toEqual({
      pathname: '/request/correction/new',
      params: { date: '2026-11-16' },
    });
    expect(pushTarget({ kind: 'attendance_reminder', action: 'correction' }, false)).toEqual({ pathname: '/(app)' });
    expect(pushTarget({ kind: 'attendance_reminder', action: 'correction', date: 'kemarin' }, false)).toEqual({ pathname: '/(app)' });
  });

  it('opens the Tim tab for coordinators only', () => {
    expect(pushTarget({ kind: 'team_missing' }, true, true)).toEqual({ pathname: '/(app)/team' });
    expect(pushTarget({ kind: 'team_missing' }, true, false)).toBeNull();
  });

  it('opens the swap list from a shift swap update and the swap approval for approvers', () => {
    expect(pushTarget({ kind: 'shift_swap', event: 'accepted', request_type: 'shift_swap', request_id: 7 }, false)).toEqual({ pathname: '/request/shift-swap' });
    expect(pushTarget({ kind: 'approval_requested', request_type: 'shift_swap', request_id: 7 }, true)).toEqual({
      pathname: '/approval/shift-swap/[id]',
      params: { id: '7' },
    });
    expect(pushTarget({ kind: 'approval_requested', request_type: 'shift_swap', request_id: 7 }, false)).toBeNull();
  });
});

describe('lent workers', () => {
  it('says which brand a worker is lent from', () => {
    const entry = { shift: { id: 1, label: '', code: 'P', name: 'Pagi', start_time: '09:00', end_time: '18:00' }, section: null, state: 'hadir' as const, borrowed_from: 'JCHICKEN' };
    expect(rosterLine(entry)).toBe('P · 09:00–18:00 · pinjam dari JCHICKEN');
  });
});

describe('weekly grid', () => {
  it('starts the week on Monday', () => {
    expect(weekStart('2026-11-05')).toBe('2026-11-02');
    expect(weekStart('2026-11-08')).toBe('2026-11-02');
    expect(weekStart('2026-11-02')).toBe('2026-11-02');
  });

  it('shows the shift code, OFF, or a dash', () => {
    const shift = { id: 1, name: 'Split', code: 'SP', talenta_code: null, label: '', segments: [] };
    expect(weekCellText({ is_day_off: false, shift })).toBe('SP');
    expect(weekCellText({ is_day_off: false, shift: { ...shift, code: null } })).toBe('Split');
    expect(weekCellText({ is_day_off: true, shift: null })).toBe('OFF');
    expect(weekCellText(undefined)).toBe('–');
  });

  const pagi: WeekCell = {
    schedule_id: 10,
    sequence: 1,
    is_day_off: false,
    shift: { id: 1, name: 'Pagi', code: 'P', talenta_code: null, label: '', segments: [] },
    location_id: 1,
    outlet: 'JChicken Ciledug',
    section: null,
    more: 1,
    locked: true,
    on_leave: false,
  };
  const malam: WeekCell = { ...pagi, schedule_id: 11, sequence: 2, shift: { ...pagi.shift!, id: 2, name: 'Malam', code: null }, more: undefined, locked: false };

  it('joins the codes of a day with several schedules', () => {
    expect(weekCellText(pagi, [pagi, malam])).toBe('P+Malam');
    // One schedule in the list reads as the single cell.
    expect(weekCellText(pagi, [pagi])).toBe('P');
    // An older server sends no list: the single cell.
    expect(weekCellText(pagi, undefined)).toBe('P');
    expect(weekCellText(undefined, [])).toBe('–');
  });

  it('reads every schedule of a date, falling back to the single cell on an older server', () => {
    expect(daySchedules({ days: { '2026-11-02': pagi }, day_schedules: { '2026-11-02': [pagi, malam] } }, '2026-11-02')).toEqual([pagi, malam]);
    expect(daySchedules({ days: { '2026-11-02': pagi } }, '2026-11-02')).toEqual([pagi]);
    expect(daySchedules({ days: {}, day_schedules: {} }, '2026-11-02')).toEqual([]);
  });

  it('marks a leave date and a schedule that already has attendance', () => {
    expect(weekCellMarks([pagi, malam])).toEqual({ onLeave: false, locked: true });
    expect(weekCellMarks([{ ...malam, on_leave: true }])).toEqual({ onLeave: true, locked: false });
    expect(weekCellMarks([{ locked: undefined, on_leave: undefined }])).toEqual({ onLeave: false, locked: false });
  });
});

describe('entrySupportsMultipleSchedules', () => {
  it('offers adding a schedule only for a real schedule row on a newer server', () => {
    expect(entrySupportsMultipleSchedules({ schedule_id: 41, schedule_count: 1 })).toBe(true);
    expect(entrySupportsMultipleSchedules({ schedule_id: 41, schedule_count: 2 })).toBe(true);
  });

  it('does not offer it for an unscheduled or overnight entry, or on an older server', () => {
    expect(entrySupportsMultipleSchedules({ schedule_id: null, schedule_count: 0 })).toBe(false);
    expect(entrySupportsMultipleSchedules({ schedule_id: 41, schedule_count: undefined })).toBe(false);
    expect(entrySupportsMultipleSchedules({ schedule_id: null, schedule_count: undefined })).toBe(false);
  });
});

