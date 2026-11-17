/// <reference types="jest" />
import { MutationObserver, onlineManager, QueryClient } from '@tanstack/react-query';

import { checkMutationOptions, submitCheck } from '../api/attendance';
import { ApiError, configureApi, NetworkError } from '../api/client';
import { isRefusal } from '../errors';

jest.mock('expo-location', () => ({
  Accuracy: { High: 4 },
  getForegroundPermissionsAsync: jest.fn(),
  requestForegroundPermissionsAsync: jest.fn(),
  hasServicesEnabledAsync: jest.fn(),
  getCurrentPositionAsync: jest.fn(),
  getLastKnownPositionAsync: jest.fn(),
}));
jest.mock('react-native', () => ({ Platform: { OS: 'android' }, Linking: {} }));

const ALREADY_CHECKED_IN = 'Anda sudah melakukan check in hari ini.';

function jsonResponse(status: number, body: unknown): Response {
  return { ok: status >= 200 && status < 300, status, text: async () => JSON.stringify(body) } as Response;
}

let fetchMock: jest.Mock;

beforeEach(() => {
  configureApi({ getBaseUrl: () => 'https://ontime.test/api/v1', getToken: () => 'token', onUnauthenticated: () => {} });
  onlineManager.setOnline(true);
});

afterEach(() => {
  jest.useRealTimers();
});

/**
 * Chaos lens: concurrency and retries on the mobile side. Scenarios the app already handles; kept as
 * regression tests.
 */
describe('check in retried after a timeout (survived)', () => {
  it('a timed-out check in fails once and is never re-sent behind the worker', async () => {
    jest.useFakeTimers();
    // The server received the request but the answer never arrives.
    fetchMock = jest.fn(() => new Promise<Response>(() => {}));
    globalThis.fetch = fetchMock as unknown as typeof fetch;

    const client = new QueryClient();
    const observer = new MutationObserver(client, {
      ...checkMutationOptions,
      mutationFn: () => submitCheck('check_in', async () => ({})),
    });

    const settled = observer.mutate().catch((caught: unknown) => caught);
    await jest.advanceTimersByTimeAsync(90000);
    const error = await settled;

    expect(error).toBeInstanceOf(NetworkError);
    expect((error as NetworkError).kind).toBe('timeout');
    await jest.advanceTimersByTimeAsync(120000);
    expect(fetchMock).toHaveBeenCalledTimes(1);
  });

  it('the worker retry that the server answers with 409 is a refusal, not a network failure to retry again', async () => {
    fetchMock = jest.fn(async () => jsonResponse(409, { message: ALREADY_CHECKED_IN }));
    globalThis.fetch = fetchMock as unknown as typeof fetch;

    const error = await submitCheck('check_in', async () => ({})).catch((caught: unknown) => caught);

    expect(error).toBeInstanceOf(ApiError);
    expect((error as ApiError).status).toBe(409);
    expect((error as ApiError).message).toBe(ALREADY_CHECKED_IN);
    expect(isRefusal(error)).toBe(true);
    expect(fetchMock).toHaveBeenCalledTimes(1);
  });

  it('two quick mutate calls on one observer send two requests only when the caller does not guard them', async () => {
    // The check screen guards with a ref (submitInFlight); the mutation itself does not dedupe, so the
    // server-side 409 is what keeps a double tap from storing two check ins.
    let calls = 0;
    fetchMock = jest.fn(async () => {
      calls += 1;
      return calls === 1
        ? jsonResponse(200, { message: 'Check in berhasil.', attendance: { id: 1, status: 'on_time' } })
        : jsonResponse(409, { message: ALREADY_CHECKED_IN });
    });
    globalThis.fetch = fetchMock as unknown as typeof fetch;

    const client = new QueryClient();
    const options = { ...checkMutationOptions, mutationFn: () => submitCheck('check_in', async () => ({})) };
    const results = await Promise.allSettled([
      new MutationObserver(client, options).mutate(),
      new MutationObserver(client, options).mutate(),
    ]);

    expect(results[0].status).toBe('fulfilled');
    expect(results[1].status).toBe('rejected');
    expect(isRefusal((results[1] as PromiseRejectedResult).reason)).toBe(true);
  });
});
