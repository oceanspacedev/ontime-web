/// <reference types="jest" />
/// <reference types="node" />
import { MutationObserver, onlineManager } from '@tanstack/react-query';
import { readFileSync } from 'fs';
import { join } from 'path';

import * as shiftSwapApi from '../api/shiftSwap';
import { ApiError, configureApi, errorMessage, failureMessage, NetworkError, request } from '../api/client';
import { isRefusal, unshownErrorMessage } from '../errors';
import { pushTarget } from '../push';
import { queryClient } from '../queryClient';
import { followToday } from '../team';
import { msUntilNextDay, unclosedNotice } from '../today';

jest.mock('expo-secure-store', () => ({
  getItemAsync: jest.fn(async () => null),
  setItemAsync: jest.fn(async () => {}),
  deleteItemAsync: jest.fn(async () => {}),
}));

const BASE_URL = 'https://ontime.test/api/v1';

function response(status: number, text: string): Response {
  return { ok: status >= 200 && status < 300, status, text: async () => text } as Response;
}

let fetchMock: jest.Mock;
let currentToken: string | null;
let unauthenticated: jest.Mock;

beforeEach(() => {
  currentToken = 'token-a';
  unauthenticated = jest.fn();
  configureApi({ getBaseUrl: () => BASE_URL, getToken: () => currentToken, onUnauthenticated: unauthenticated });
  fetchMock = jest.fn(async () => response(200, JSON.stringify({ message: 'Pengajuan berhasil disetujui', status: 'approved', current_step: null })));
  globalThis.fetch = fetchMock as unknown as typeof fetch;
  onlineManager.setOnline(true);
});

afterEach(() => {
  onlineManager.setOnline(true);
  // clear() drops mutations but leaves their 5-minute gc timers running, which keeps Jest from exiting.
  queryClient.getMutationCache().getAll().forEach((mutation) => mutation.destroy());
  queryClient.clear();
  jest.useRealTimers();
});

/** Lets pending promises and React Query's scheduler run. */
const settle = () => new Promise((resolve) => setTimeout(resolve, 20));

// ── Findings ────────────────────────────────────────────────────────────────

describe('chaos: a captive portal (mall / outlet Wi-Fi) answers 200 with an HTML login page', () => {
  it('is reported as a failure with an Indonesian message, never handed to the screen as data', async () => {
    fetchMock.mockImplementation(async () => response(200, '<!DOCTYPE html><html><body>Login WiFi Mall</body></html>'));

    // The check-in screen then reads `response.attendance.status`, the today card renders nothing and
    // `show()` reads `.data` of null: a raw English TypeError instead of a connection message.
    const outcome = await request<{ data: unknown }>('/attendance/today').then(
      (value) => ({ resolved: true as const, value }),
      (error: unknown) => ({ resolved: false as const, error }),
    );

    expect(outcome.resolved).toBe(false);
  });

  it('does not surface a JavaScript TypeError from a detail screen', async () => {
    fetchMock.mockImplementation(async () => response(200, '<html>Hotspot login</html>'));

    const error = await shiftSwapApi.show(12).then(
      () => null,
      (caught: unknown) => caught,
    );

    expect(error).not.toBeInstanceOf(TypeError);
    expect(errorMessage(error)).not.toMatch(/Cannot read|null|undefined/);
  });
});

describe('chaos: a request form submitted while offline', () => {
  it('fails at once with the offline message instead of waiting silently in a paused queue', async () => {
    // Every form except the check uses the app queryClient defaults (React Query networkMode "online").
    const observer = new MutationObserver(queryClient, {
      mutationFn: () => shiftSwapApi.process(5, 'approved', null, 1),
    });
    onlineManager.setOnline(false);

    let settledError: unknown = 'still pending';
    void observer.mutate(undefined).catch((error: unknown) => {
      settledError = error;
    });
    await settle();

    // The approve button keeps spinning with no message: the mutation is paused, not failed.
    expect(observer.getCurrentResult().isPaused).toBe(false);
    expect(settledError).toBeInstanceOf(NetworkError);
  });

  it('is never sent later, behind the user, after they left the screen', async () => {
    const onSuccess = jest.fn();
    const observer = new MutationObserver(queryClient, {
      mutationFn: () => shiftSwapApi.process(5, 'approved', null, 1),
      // The screens put Alert + router.back() here; it would fire on whatever screen is open by then.
      onSuccess,
    });
    onlineManager.setOnline(false);
    void observer.mutate(undefined).catch(() => {});
    await settle();

    // The approver gives up and closes the screen.
    observer.reset();

    // Connection back: QueryClient.mount() resumes paused mutations exactly like this.
    onlineManager.setOnline(true);
    await queryClient.resumePausedMutations();
    await settle();

    expect(fetchMock).not.toHaveBeenCalled();
    expect(onSuccess).not.toHaveBeenCalled();
  });
});

describe('chaos: push for the requester of a Tukar Shift that an approver decided', () => {
  it('opens the swap list, as the server only tells the requester through approval_resolved', () => {
    // Payload sent by ApprovalResolvedNotification (see Chaos_mobileTest::test_requester_of_an_approved_...).
    const approved = { kind: 'approval_resolved', request_type: 'shift_swap', request_id: 12, status: 'approved' };
    const rejected = { kind: 'approval_resolved', request_type: 'shift_swap', request_id: 12, status: 'rejected' };

    expect(pushTarget(approved, false)).toEqual({ pathname: '/request/shift-swap' });
    expect(pushTarget(rejected, false)).toEqual({ pathname: '/request/shift-swap' });
  });
});

describe('chaos: roster cache after an approver decides in the app', () => {
  const read = (path: string) => readFileSync(join(__dirname, '..', '..', 'app', path), 'utf8');
  const invalidatesTeam = (source: string) => /invalidateQueries\(\{\s*queryKey:\s*(queryKeys\.team|\[\s*'team'\s*\])\s*\}\)/.test(source);
  const invalidatesToday = (source: string) => /invalidateQueries\(\{\s*queryKey:\s*(queryKeys\.today|\[\s*'attendance'\s*\])/.test(source);

  it('a final Tukar Shift approval swaps the schedules, so the Tim roster/week and today are reloaded', () => {
    const source = read('approval/shift-swap/[id].tsx');

    expect(invalidatesTeam(source)).toBe(true);
    expect(invalidatesToday(source)).toBe(true);
  });

  it('approving an off-schedule check-in that replaces a colleague gives the colleague a day off, so the Tim roster is reloaded', () => {
    expect(invalidatesTeam(read('approval/[type]/[id].tsx'))).toBe(true);
  });
});

// ── Survived ────────────────────────────────────────────────────────────────

describe('survived: token expiry and sign-out races', () => {
  it('signs out on a 401 for the token in use', async () => {
    fetchMock.mockImplementation(async () => response(401, JSON.stringify({ message: 'Unauthenticated.' })));

    await expect(request('/attendance/today')).rejects.toBeInstanceOf(ApiError);
    expect(unauthenticated).toHaveBeenCalledTimes(1);
  });

  it('ignores a late 401 for a token that was replaced meanwhile (logout then login while a request was out)', async () => {
    let answer: (value: Response) => void = () => {};
    fetchMock.mockImplementation(() => new Promise<Response>((resolve) => (answer = resolve)));

    const pending = request('/attendance/today');
    await settle();
    currentToken = 'token-b';
    answer(response(401, JSON.stringify({ message: 'Unauthenticated.' })));

    await expect(pending).rejects.toBeInstanceOf(ApiError);
    expect(unauthenticated).not.toHaveBeenCalled();
  });
});

describe('survived: server refusals reach the user in Indonesian, without a retry that cannot help', () => {
  it('shows the 409 of a check retried after a lost answer and offers no retry', async () => {
    fetchMock.mockImplementation(async () => response(409, JSON.stringify({ message: 'Sudah melakukan check in hari ini.' })));

    const error = await request('/attendance/check-in', { method: 'POST' }).catch((caught: unknown) => caught);

    expect(errorMessage(error)).toBe('Sudah melakukan check in hari ini.');
    expect(isRefusal(error)).toBe(true);
  });

  it('shows a 422 keyed to a field the form lacks (shift_swap) in the footer notice', async () => {
    const message = 'Sudah ada pengajuan tukar shift lain yang masih berjalan untuk tanggal tersebut. Batalkan atau tunggu pengajuan itu selesai.';
    fetchMock.mockImplementation(async () => response(422, JSON.stringify({ message, errors: { shift_swap: [message] } })));

    const error = await request('/shift-swaps', { method: 'POST', json: {} }).catch((caught: unknown) => caught);

    expect(unshownErrorMessage(error, ['requester_date', 'counterpart_date', 'colleague_id', 'reason'])).toBe(message);
  });

  it('replaces Laravel English defaults and proxy HTML on 5xx/413', () => {
    expect(failureMessage(500, 'Server Error', false)).toBe('Terjadi kesalahan di server. Coba lagi beberapa saat.');
    expect(failureMessage(502, undefined, true)).toBe('Server sedang tidak tersedia. Coba lagi beberapa saat.');
    expect(failureMessage(413, 'The POST data is too large.', false)).toMatch(/^Ukuran foto/);
  });

  it('turns a submit that hangs past its deadline into the timeout message', async () => {
    jest.useFakeTimers();
    fetchMock.mockImplementation(() => new Promise<Response>(() => {}));

    const pending = request('/attendance/check-in', { method: 'POST', timeoutMs: 90000 }).catch((caught: unknown) => caught);
    jest.advanceTimersByTime(90000);
    const error = await pending;

    expect(error).toBeInstanceOf(NetworkError);
    expect((error as NetworkError).kind).toBe('timeout');
  });
});

describe('survived: the app backgrounded across midnight', () => {
  it('plans the next refresh just after local midnight', () => {
    expect(msUntilNextDay(new Date(2026, 10, 17, 23, 59, 30))).toBe(31000);
    // Right before midnight the timer still lands after the date has turned, never at or before it.
    expect(msUntilNextDay(new Date(2026, 10, 17, 23, 59, 59, 800))).toBe(1200);
  });

  it('moves the Tim day view from the old today to the new one, however many days passed', () => {
    expect(followToday('2026-11-17', '2026-11-17', '2026-11-19')).toBe('2026-11-19');
    expect(followToday('2026-11-20', '2026-11-17', '2026-11-18')).toBe('2026-11-20');
  });

  it('names the unclosed shift by the server date, not the device clock', () => {
    const notice = unclosedNotice({ date: '2026-11-16', check_in: '22:00', shift: 'Malam', correction_pending: false }, '2026-11-17');

    expect(notice?.message).toMatch(/^Kemarin/);
    expect(notice?.canRequestCorrection).toBe(true);
  });
});
