/// <reference types="jest" />
import { ApiError, configureApi, errorMessage, isNetworkError, NetworkError, request } from '../api/client';
import { queryClient } from '../queryClient';

const OFFLINE_MESSAGE = 'Tidak ada koneksi internet. Periksa koneksi Anda lalu coba lagi.';
const TIMEOUT_MESSAGE = 'Koneksi internet lambat atau terputus. Periksa koneksi Anda lalu coba lagi.';

function jsonResponse(status: number, body: unknown): Response {
  return { ok: status >= 200 && status < 300, status, text: async () => JSON.stringify(body) } as Response;
}

function mockFetch(implementation: (input: RequestInfo | URL, init?: RequestInit) => Promise<Response>): jest.Mock {
  const mock = jest.fn(implementation);
  globalThis.fetch = mock as unknown as typeof fetch;
  return mock;
}

/** Runs the request and hands back what it threw. */
async function failureOf(promise: Promise<unknown>): Promise<unknown> {
  try {
    await promise;
  } catch (error) {
    return error;
  }
  throw new Error('The request was expected to fail.');
}

beforeEach(() => {
  configureApi({ getBaseUrl: () => 'https://contoh.id/api/v1', getToken: () => 'sesi-token', onUnauthenticated: () => {} });
});

afterEach(() => {
  jest.useRealTimers();
});

describe('a request that cannot reach the server', () => {
  it.each([
    // Expo's fetch on Android, as thrown without a connection.
    ['Expo fetch, Android', new Error('fetch failed: Unable to resolve host "contoh.id": No address associated with hostname')],
    // Expo's fetch on iOS.
    ['Expo fetch, iOS', new Error('fetch failed: The Internet connection appears to be offline.')],
    // React Native's own fetch.
    ['React Native fetch', new TypeError('Network request failed')],
  ])('becomes a NetworkError with a friendly message (%s)', async (_name, thrown) => {
    mockFetch(async () => {
      throw thrown;
    });

    const error = await failureOf(request('/attendance/today'));

    expect(error).toBeInstanceOf(NetworkError);
    expect(isNetworkError(error)).toBe(true);
    expect((error as NetworkError).kind).toBe('offline');
    expect((error as NetworkError).cause).toBe(thrown);
    expect(errorMessage(error)).toBe(OFFLINE_MESSAGE);
  });

  it('never shows the technical reason to the user', async () => {
    mockFetch(async () => {
      throw new Error('fetch failed: Unable to resolve host "contoh.id": No address associated with hostname');
    });

    const message = errorMessage(await failureOf(request('/auth/login', { method: 'POST', json: { email: 'a@contoh.id' } })));

    expect(message).not.toMatch(/fetch failed|resolve host|contoh\.id/i);
  });
});

describe('a request that takes too long', () => {
  it('gives up after the timeout and cancels the request', async () => {
    jest.useFakeTimers();
    let signal: AbortSignal | null | undefined;
    mockFetch((_input, init) => {
      signal = init?.signal;
      return new Promise(() => {});
    });

    const pending = failureOf(request('/attendance/today', { timeoutMs: 5000 }));
    await jest.advanceTimersByTimeAsync(5000);
    const error = await pending;

    expect(error).toBeInstanceOf(NetworkError);
    expect((error as NetworkError).kind).toBe('timeout');
    expect(errorMessage(error)).toBe(TIMEOUT_MESSAGE);
    expect(signal?.aborted).toBe(true);
  });

  it('also gives up when the answer starts but its body never finishes', async () => {
    jest.useFakeTimers();
    mockFetch(async () => ({ ok: true, status: 200, text: () => new Promise<string>(() => {}) }) as Response);

    const pending = failureOf(request('/attendance/today', { timeoutMs: 5000 }));
    await jest.advanceTimersByTimeAsync(5000);

    expect((await pending) as NetworkError).toMatchObject({ name: 'NetworkError', kind: 'timeout' });
  });

  it('reports a timeout even when the cancelled fetch fails with its own error', async () => {
    jest.useFakeTimers();
    // Expo's fetch rejects a cancelled request with a plain Error, not an AbortError.
    mockFetch(
      (_input, init) =>
        new Promise((_resolve, reject) => {
          init?.signal?.addEventListener('abort', () => reject(new Error('fetch failed: Fetch request has been canceled')));
        }),
    );

    const pending = failureOf(request('/attendance/today', { timeoutMs: 5000 }));
    await jest.advanceTimersByTimeAsync(5000);

    expect(errorMessage(await pending)).toBe(TIMEOUT_MESSAGE);
  });

  it('leaves no timer behind after an answer', async () => {
    jest.useFakeTimers();
    mockFetch(async () => jsonResponse(200, { ok: true }));

    await expect(request('/attendance/today')).resolves.toEqual({ ok: true });

    expect(jest.getTimerCount()).toBe(0);
  });
});

describe('an answer from the server', () => {
  it('is not a network failure, whatever its status', async () => {
    mockFetch(async () => jsonResponse(422, { message: 'Data tidak valid.', errors: { email: ['Email wajib diisi.'] } }));

    const error = await failureOf(request('/auth/login', { method: 'POST', json: {} }));

    expect(error).toBeInstanceOf(ApiError);
    expect(isNetworkError(error)).toBe(false);
    expect(errorMessage(error)).toBe('Email wajib diisi.');
  });

  it('keeps the message of errors raised inside the app', () => {
    expect(errorMessage(new Error('Minta kode OTP terlebih dahulu.'))).toBe('Minta kode OTP terlebih dahulu.');
    expect(errorMessage('bukan error')).toBe('Terjadi kesalahan yang tidak diketahui.');
  });
});

describe('automatic retries of queries', () => {
  const retry = queryClient.getDefaultOptions().queries?.retry as (failureCount: number, error: Error) => boolean;

  it('tries again twice when the server could not be reached', () => {
    const offline = new NetworkError('offline');

    expect(retry(0, offline)).toBe(true);
    expect(retry(1, offline)).toBe(true);
    expect(retry(2, offline)).toBe(false);
  });

  it('does not make the user wait through another timeout', () => {
    expect(retry(0, new NetworkError('timeout'))).toBe(false);
  });

  it('does not repeat a request the server refused', () => {
    expect(retry(0, new ApiError(422, 'Data tidak valid.'))).toBe(false);
    expect(retry(0, new ApiError(503, 'Server sibuk.'))).toBe(true);
  });
});
