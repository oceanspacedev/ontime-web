import type { RosterEntry, RosterOutlet, TeamRoster } from '../api/types';
import { followToday, outletTitle, rosterBadge, rosterEntryKey, rosterSubtitle, rosterSummary, teamAttention } from '../team';

const zero = { scheduled: 0, hadir: 0, terlambat: 0, pulang: 0, menunggu: 0, belum: 0, nanti: 0, libur: 0, cuti: 0, jeda: 0 };
const shift = { id: 1, name: 'Pagi', code: 'P', label: '', start_time: '08:00', end_time: '17:00' };

function entry(overrides: Partial<RosterEntry>): RosterEntry {
  return {
    schedule_id: 1,
    worker: { type: 'freelancer', id: 1, company_id: 1, number: 'FL-1', name: 'Ani' },
    shift,
    location_id: 1,
    section: null,
    state: 'hadir',
    check_in: '07:55',
    check_out: null,
    late_minutes: 0,
    ...overrides,
  };
}

function outlet(overrides: Partial<RosterOutlet>): RosterOutlet {
  return { location: { id: 1, name: 'JChicken Ciledug' }, counts: zero, entries: [], ...overrides };
}

describe('rosterSummary with live states', () => {
  it('counts checked-in people from the server, so pending and split breaks are included', () => {
    const roster: TeamRoster = {
      date: '2026-11-17',
      outlets: [outlet({ counts: { ...zero, scheduled: 4, checked_in: 3, hadir: 1, menunggu: 1, jeda: 1, belum: 1 } })],
    };
    expect(rosterSummary(roster, '2026-11-17')).toBe('3 dari 4 orang sudah absen');
  });

  it('falls back to the states of an older server, waiting and break included', () => {
    const roster: TeamRoster = { date: '2026-11-17', outlets: [outlet({ counts: { ...zero, scheduled: 4, hadir: 1, menunggu: 1, jeda: 1, belum: 1 } })] };
    expect(rosterSummary(roster, '2026-11-17')).toBe('3 dari 4 orang sudah absen');
  });

  it('names the people off the schedule apart, without changing the scheduled count', () => {
    const roster: TeamRoster = {
      date: '2026-11-17',
      outlets: [
        outlet({ counts: { ...zero, scheduled: 2, checked_in: 1, hadir: 1, nanti: 1 } }),
        outlet({ location: null, kind: 'unscheduled', counts: { ...zero, checked_in: 1, hadir: 1 }, entries: [entry({ schedule_id: null, note: 'Tanpa jadwal' })] }),
        outlet({ location: null, kind: 'overnight', counts: { ...zero, checked_in: 1, hadir: 1 }, entries: [entry({ schedule_id: null, worker: { type: 'employee', id: 9, company_id: 1, number: 'E9', name: 'Eko' } })] }),
      ],
    };
    expect(rosterSummary(roster, '2026-11-17')).toBe('1 dari 2 orang sudah absen · 1 di luar roster · 1 dari shift semalam');
  });

  it('still reports walk-ins on a day without schedules', () => {
    const roster: TeamRoster = { date: '2026-11-17', outlets: [outlet({ location: null, kind: 'unscheduled', counts: { ...zero, checked_in: 2 }, entries: [entry({}), entry({})] })] };
    expect(rosterSummary(roster, '2026-11-17')).toBe('Belum ada jadwal · 2 di luar roster');
  });
});

describe('split shift missing segment 2', () => {
  it('is "belum absen" only: the title and the attention line do not count the same person twice', () => {
    // What the server sends for Citra (split 07:45–13:00 / 17:45–22:00) at 18:10, segment 1 done.
    const citra = entry({ worker: { type: 'freelancer', id: 3, company_id: 1, number: 'FL-3', name: 'Citra' }, state: 'belum', check_in: '07:50', note: 'Belum absen segmen 2 (mulai 17:45)' });
    const roster: TeamRoster = { date: '2026-11-17', outlets: [outlet({ counts: { ...zero, scheduled: 1, checked_in: 0, belum: 1 }, entries: [citra] })] };

    expect(rosterSummary(roster, '2026-11-17')).toBe('0 dari 1 orang sudah absen');
    expect(teamAttention(roster)).toBe('1 belum absen');
    expect(rosterSubtitle(citra)).toContain('Belum absen segmen 2');
  });
});

describe('followToday', () => {
  it('moves a view left on today to the new today, however many days passed', () => {
    expect(followToday('2026-11-16', '2026-11-16', '2026-11-17')).toBe('2026-11-17');
    expect(followToday('2026-11-15', '2026-11-15', '2026-11-17')).toBe('2026-11-17');
  });

  it('keeps a date picked by hand', () => {
    expect(followToday('2026-11-20', '2026-11-16', '2026-11-17')).toBe('2026-11-20');
    expect(followToday('2026-11-10', '2026-11-16', '2026-11-17')).toBe('2026-11-10');
  });
});

describe('outletTitle', () => {
  it('titles groups off the schedule, outlets, and days off', () => {
    expect(outletTitle(outlet({ kind: 'unscheduled', location: null }))).toBe('Di luar roster');
    expect(outletTitle(outlet({ kind: 'overnight', location: null }))).toBe('Shift semalam');
    expect(outletTitle(outlet({}))).toBe('JChicken Ciledug');
    expect(outletTitle(outlet({ location: null, entries: [entry({ state: 'libur' })] }))).toBe('Libur');
    expect(outletTitle(outlet({ location: null, entries: [entry({})] }))).toBe('Tanpa outlet');
  });
});

describe('roster rows', () => {
  it('shows a split shift break with a badge and the next segment', () => {
    const split = entry({
      state: 'jeda',
      check_in: '07:50',
      note: 'Jeda · segmen 2 mulai 17:45',
      shift: { ...shift, code: 'SP', segments: [{ start: '07:45', end: '13:00' }, { start: '17:45', end: '22:00' }] },
    });
    expect(rosterBadge(split, '2026-11-17', '2026-11-17')).toEqual({ label: 'Jeda', tone: 'info' });
    // Real-device QA: a refused out-of-area check-in read "Hadir" on the PIC's Tim view.
    expect(rosterBadge(entry({ state: 'ditolak', check_in: '10:30' }), '2026-11-17', '2026-11-17')).toEqual({ label: 'Ditolak', tone: 'danger' });
    expect(rosterSubtitle(split)).toBe('SP · 07:45–13:00 & 17:45–22:00 · masuk 07:50 · Jeda · segmen 2 mulai 17:45');
  });

  it('shows last night\'s shift and walk-ins with their check-in', () => {
    expect(rosterSubtitle(entry({ state: 'terlambat', check_in: '00:30', note: 'Shift semalam (16 Nov) · belum absen pulang' }))).toBe(
      'P · 08:00–17:00 · masuk 00:30 · Shift semalam (16 Nov) · belum absen pulang',
    );
    expect(rosterSubtitle(entry({ shift: null, note: 'Tanpa jadwal', check_in: '08:05' }))).toBe('masuk 08:05 · Tanpa jadwal');
  });

  it('keeps plain rows as before and explains a check-in on a day off', () => {
    expect(rosterSubtitle(entry({ check_in: null, state: 'nanti' }))).toBe('P · 08:00–17:00');
    expect(rosterSubtitle(entry({ state: 'libur', check_in: null }))).toBeUndefined();
    expect(rosterSubtitle(entry({ state: 'libur', check_in: '08:00', note: 'Masuk di hari libur' }))).toBe('Masuk di hari libur · masuk 08:00');
  });

  it('keys rows without a schedule apart', () => {
    expect(rosterEntryKey(entry({}))).toBe('freelancer:1:1');
    expect(rosterEntryKey(entry({ schedule_id: null }))).toBe('freelancer:1:tanpa-jadwal');
  });
});
