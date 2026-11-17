import { request } from '../api/client';
import { freelancers } from '../api/team';
import { canEditSchedule, nextPage, rosterBadge, teamAttention } from '../team';
import type { TeamRoster } from '../api/types';

jest.mock('../api/client', () => ({ request: jest.fn(async () => ({ data: [], pagination: { current_page: 2, last_page: 2, total: 45 } })) }));

describe('freelancer list paging', () => {
  it('asks the server for the requested page so people past the first 30 can be reached', async () => {
    await freelancers('active', '', 2);
    expect(request).toHaveBeenLastCalledWith('/team/freelancers', { query: { status: 'active', search: undefined, page: 2 } });

    await freelancers('ended', 'bay');
    expect(request).toHaveBeenLastCalledWith('/team/freelancers', { query: { status: 'ended', search: 'bay', page: 1 } });
  });

  it('offers a next page until the last one', () => {
    expect(nextPage({ current_page: 1, last_page: 2 })).toBe(2);
    expect(nextPage({ current_page: 2, last_page: 2 })).toBeUndefined();
    expect(nextPage({ current_page: 1, last_page: 1 })).toBeUndefined();
  });
});

describe('approved leave on the roster', () => {
  it('shows Cuti in the info tone instead of a red Belum absen, also on later days', () => {
    expect(rosterBadge({ state: 'cuti' }, '2026-11-23', '2026-11-23')).toEqual({ label: 'Cuti', tone: 'info' });
    expect(rosterBadge({ state: 'cuti' }, '2026-11-24', '2026-11-23')).toEqual({ label: 'Cuti', tone: 'info' });
  });

  it('keeps people on leave out of the home card warning', () => {
    const counts = { scheduled: 0, hadir: 0, terlambat: 0, pulang: 0, menunggu: 0, belum: 0, nanti: 0, libur: 0, cuti: 1 };
    const roster: TeamRoster = { date: '2026-11-23', outlets: [{ location: { id: 1, name: 'JChicken' }, counts, entries: [] }] };
    expect(teamAttention(roster)).toBeUndefined();
  });
});

describe('workers lent in from another brand', () => {
  it('are read-only only when the server says so', () => {
    expect(canEditSchedule({ can_edit: false })).toBe(false);
    expect(canEditSchedule({ can_edit: true })).toBe(true);
    expect(canEditSchedule({})).toBe(true);
  });
});
