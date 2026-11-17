import { countPending } from '../approvals';
import type { PendingApprovals, ShiftSwapRequest } from '../api/types';
import {
  colleagueKey,
  isShiftSwapOpen,
  pendingShiftSwapCount,
  shiftSwapDraftErrors,
  shiftSwapHeadline,
  shiftSwapOutcomeNote,
  shiftSwapStatusLabel,
  shiftSwapStatusTone,
} from '../shiftSwap';

const people = {
  requester: { type: 'employee' as const, id: 1, full_name: 'Andi', division: null },
  counterpart: { type: 'freelancer' as const, id: 2, full_name: 'Budi', division: null },
};

describe('shift swap status', () => {
  it('labels and colours every status', () => {
    expect(shiftSwapStatusLabel('awaiting_colleague')).toBe('Menunggu Rekan');
    expect(shiftSwapStatusLabel('failed')).toBe('Gagal Diterapkan');
    expect(shiftSwapStatusTone('approved')).toBe('success');
    expect(shiftSwapStatusTone('pending')).toBe('warning');
    expect(shiftSwapStatusTone('declined')).toBe('danger');
    expect(shiftSwapStatusTone('expired')).toBe('gray');
  });

  it('falls back to the raw status for one it does not know', () => {
    expect(shiftSwapStatusLabel('archived')).toBe('archived');
    expect(shiftSwapStatusTone('archived')).toBe('gray');
  });

  it('is open only while waiting for the colleague or an approver', () => {
    expect(isShiftSwapOpen('awaiting_colleague')).toBe(true);
    expect(isShiftSwapOpen('pending')).toBe(true);
    expect(isShiftSwapOpen('approved')).toBe(false);
    expect(isShiftSwapOpen('cancelled')).toBe(false);
  });

  it('explains why a request ended without a swap', () => {
    expect(shiftSwapOutcomeNote({ status: 'declined', rejection_reason: 'Ada acara', failure_reason: null })).toBe('Rekan menolak: Ada acara');
    expect(shiftSwapOutcomeNote({ status: 'declined', rejection_reason: null, failure_reason: null })).toBe('Rekan menolak ajakan ini.');
    expect(shiftSwapOutcomeNote({ status: 'failed', rejection_reason: null, failure_reason: 'Jadwal berubah' })).toBe('Jadwal berubah');
    expect(shiftSwapOutcomeNote({ status: 'approved', rejection_reason: null, failure_reason: null })).toBeNull();
  });
});

describe('shift swap headline', () => {
  it('names the colleague for the requester and the requester for the colleague', () => {
    expect(shiftSwapHeadline({ type: 'swap', is_mine: true, is_for_me: false, ...people })).toBe('Tukar Shift dengan Budi');
    expect(shiftSwapHeadline({ type: 'give_away', is_mine: true, is_for_me: false, ...people })).toBe('Serahkan Shift ke Budi');
    expect(shiftSwapHeadline({ type: 'swap', is_mine: false, is_for_me: true, ...people })).toBe('Tukar Shift dari Andi');
  });

  it('shows both names to an approver, and survives missing workers', () => {
    expect(shiftSwapHeadline({ type: 'swap', is_mine: false, is_for_me: false, ...people })).toBe('Andi ⇄ Budi');
    expect(shiftSwapHeadline({ type: 'swap', is_mine: false, is_for_me: false, requester: null, counterpart: null })).toBe('Pengaju ⇄ Rekan');
  });
});

describe('shift swap draft', () => {
  const today = '2026-11-16';

  it('accepts today and later', () => {
    expect(shiftSwapDraftErrors({ type: 'swap', requesterDate: today, counterpartDate: '2026-11-19', colleagueKey: 'employee:2' }, today)).toEqual({});
  });

  it('requires the dates and the colleague', () => {
    expect(shiftSwapDraftErrors({ type: 'swap', requesterDate: null, counterpartDate: null, colleagueKey: null }, today)).toEqual({
      requester_date: 'Tanggal shift Anda wajib diisi.',
      counterpart_date: 'Tanggal shift rekan wajib diisi.',
      colleague_id: 'Pilih rekan kerja.',
    });
  });

  it('refuses past dates, also across a month or year boundary', () => {
    expect(shiftSwapDraftErrors({ type: 'swap', requesterDate: '2026-11-15', counterpartDate: '2026-11-17', colleagueKey: 'employee:2' }, today)).toHaveProperty('requester_date');
    expect(shiftSwapDraftErrors({ type: 'swap', requesterDate: '2027-01-01', counterpartDate: '2026-12-31', colleagueKey: 'employee:2' }, '2027-01-01')).toHaveProperty('counterpart_date');
  });

  it('does not ask the colleague date for a give away', () => {
    expect(shiftSwapDraftErrors({ type: 'give_away', requesterDate: today, counterpartDate: null, colleagueKey: 'intern:4' }, today)).toEqual({});
  });
});

describe('shift swap inbox count', () => {
  const empty: PendingApprovals = {
    leave_requests: [],
    overtime_requests: [],
    attendance_corrections: [],
    geofence_attendances: [],
    resignations: [],
  };

  it('counts shift swaps, and tolerates a server that does not send them', () => {
    expect(pendingShiftSwapCount(undefined)).toBe(0);
    expect(pendingShiftSwapCount(empty)).toBe(0);
    const withSwap = { ...empty, shift_swaps: [{ id: 1 } as ShiftSwapRequest] };
    expect(pendingShiftSwapCount(withSwap)).toBe(1);
    expect(countPending(withSwap)).toBe(1);
  });

  it('builds a colleague key per worker type', () => {
    expect(colleagueKey({ type: 'freelancer', id: 7 })).toBe('freelancer:7');
  });
});
