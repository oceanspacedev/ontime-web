import { deriveAccess } from '../access';
import type { User } from '../api/types';
import { rosterBadge, rosterLine, shiftDate, shiftText } from '../team';

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
