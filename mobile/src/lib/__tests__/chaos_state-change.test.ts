import { ApiError } from '../api/client';
import { isForbidden, isRefusal } from '../errors';
import { isShiftSwapOpen, shiftSwapOutcomeNote, shiftSwapStatusLabel, shiftSwapStatusTone } from '../shiftSwap';

/**
 * Chaos lens "state change" (survived): what the app shows when the server closes something because the
 * worker, a schedule or the account changed while it was in flight.
 */
describe('chaos: state change mid-flow', () => {
  it('shows a swap that failed at the final approval as closed, with the server reason', () => {
    const reason = 'Salah satu pekerja sudah tidak aktif, tukar shift tidak bisa diterapkan.';

    expect(isShiftSwapOpen('failed')).toBe(false);
    expect(shiftSwapStatusLabel('failed')).toBe('Gagal Diterapkan');
    expect(shiftSwapStatusTone('failed')).toBe('danger');
    expect(shiftSwapOutcomeNote({ status: 'failed', rejection_reason: null, failure_reason: reason })).toBe(reason);
  });

  it('shows a swap expired by the nightly job as closed', () => {
    expect(isShiftSwapOpen('expired')).toBe(false);
    expect(shiftSwapOutcomeNote({ status: 'expired', rejection_reason: null, failure_reason: 'Tanggal tukar shift sudah lewat.' })).toBe(
      'Tanggal tukar shift sudah lewat.',
    );
  });

  it('treats a worker made inactive mid-shift (403) as a refusal that a retry cannot fix', () => {
    const inactive = new ApiError(403, 'Status kerja Anda tidak aktif. Hubungi koordinator atau HR.');

    expect(isForbidden(inactive)).toBe(true);
    expect(isRefusal(inactive)).toBe(true);
  });

  it('treats a roster changed under a check-in (409) as a refusal, not a network retry', () => {
    const changed = new ApiError(409, 'Jadwal Anda berubah. Muat ulang lalu coba lagi.');

    expect(isRefusal(changed)).toBe(true);
    expect(isForbidden(changed)).toBe(false);
  });
});
