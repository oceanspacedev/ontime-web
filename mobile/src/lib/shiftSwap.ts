import type { PendingApprovals, ShiftSwapRequest, ShiftSwapStatus, ShiftSwapType } from './api/types';
import type { Tone } from './format';

/** Every Tukar Shift query lives under this key, so one invalidation refreshes the lists and details. */
export const SHIFT_SWAP_QUERY_ROOT = ['requests', 'shift_swap'] as const;

export function shiftSwapQueryKey(box: string) {
  return [...SHIFT_SWAP_QUERY_ROOT, box] as const;
}

export function shiftSwapDetailKey(id: number) {
  return [...SHIFT_SWAP_QUERY_ROOT, 'detail', id] as const;
}

export const SHIFT_SWAP_TYPE_LABEL: Record<ShiftSwapType, string> = {
  swap: 'Tukar Shift',
  give_away: 'Serahkan Shift',
};

const STATUS_LABEL: Record<ShiftSwapStatus, string> = {
  awaiting_colleague: 'Menunggu Rekan',
  pending: 'Menunggu Persetujuan',
  approved: 'Disetujui',
  rejected: 'Ditolak',
  declined: 'Ditolak Rekan',
  cancelled: 'Dibatalkan',
  expired: 'Kedaluwarsa',
  failed: 'Gagal Diterapkan',
};

export function shiftSwapStatusLabel(status: ShiftSwapStatus | string): string {
  return STATUS_LABEL[status as ShiftSwapStatus] ?? status;
}

export function shiftSwapStatusTone(status: ShiftSwapStatus | string): Tone {
  switch (status) {
    case 'approved':
      return 'success';
    case 'awaiting_colleague':
    case 'pending':
      return 'warning';
    case 'rejected':
    case 'declined':
    case 'failed':
      return 'danger';
    default:
      return 'gray';
  }
}

/** Whether the request is still open: waiting for the colleague or for an approver. */
export function isShiftSwapOpen(status: ShiftSwapStatus | string): boolean {
  return status === 'awaiting_colleague' || status === 'pending';
}

/** Why the request ended without a swap (declined, rejected, expired, failed), or null. */
export function shiftSwapOutcomeNote(swap: Pick<ShiftSwapRequest, 'status' | 'rejection_reason' | 'failure_reason'>): string | null {
  switch (swap.status) {
    case 'declined':
      return swap.rejection_reason ? `Rekan menolak: ${swap.rejection_reason}` : 'Rekan menolak ajakan ini.';
    case 'rejected':
      return swap.rejection_reason ? `Ditolak atasan: ${swap.rejection_reason}` : 'Ditolak atasan.';
    case 'expired':
    case 'failed':
      return swap.failure_reason ?? null;
    default:
      return null;
  }
}

/**
 * One line naming the other worker from the viewer's side: "Dengan Budi" for the requester, "Dari Andi" for
 * the colleague, "Andi ⇄ Budi" for an approver.
 */
export function shiftSwapHeadline(swap: Pick<ShiftSwapRequest, 'type' | 'is_mine' | 'is_for_me' | 'requester' | 'counterpart'>): string {
  const requester = swap.requester?.full_name ?? 'Pengaju';
  const counterpart = swap.counterpart?.full_name ?? 'Rekan';
  const label = SHIFT_SWAP_TYPE_LABEL[swap.type] ?? 'Tukar Shift';

  if (swap.is_mine) {
    return swap.type === 'give_away' ? `${label} ke ${counterpart}` : `${label} dengan ${counterpart}`;
  }
  if (swap.is_for_me) {
    return `${label} dari ${requester}`;
  }
  return `${requester} ⇄ ${counterpart}`;
}

export interface ShiftSwapDraft {
  type: ShiftSwapType;
  requesterDate: string | null;
  counterpartDate: string | null;
  colleagueKey: string | null;
}

/**
 * Checks done before sending, mirroring the server: dates are "YYYY-MM-DD" and compared as text, so today
 * (the device's date) and later are allowed; a swap needs the colleague's date, a give away does not.
 */
export function shiftSwapDraftErrors(draft: ShiftSwapDraft, today: string): Record<string, string> {
  const errors: Record<string, string> = {};

  if (!draft.requesterDate) {
    errors.requester_date = 'Tanggal shift Anda wajib diisi.';
  } else if (draft.requesterDate < today) {
    errors.requester_date = 'Tanggal sudah lewat. Pilih hari ini atau tanggal berikutnya.';
  }

  if (draft.type === 'swap') {
    if (!draft.counterpartDate) {
      errors.counterpart_date = 'Tanggal shift rekan wajib diisi.';
    } else if (draft.counterpartDate < today) {
      errors.counterpart_date = 'Tanggal sudah lewat. Pilih hari ini atau tanggal berikutnya.';
    }
  }

  if (!draft.colleagueKey) {
    errors.colleague_id = 'Pilih rekan kerja.';
  }

  return errors;
}

/** Stable key of a colleague across worker types ("employee:12"). */
export function colleagueKey(colleague: { type: string; id: number }): string {
  return `${colleague.type}:${colleague.id}`;
}

/** Tukar Shift requests waiting on the approver, for the inbox badge. */
export function pendingShiftSwapCount(pending: PendingApprovals | undefined): number {
  return pending?.shift_swaps?.length ?? 0;
}
