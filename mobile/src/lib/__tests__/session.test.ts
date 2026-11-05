/// <reference types="jest" />
import type { User } from '../api/types';

const mockSecureStore = new Map<string, string>();

jest.mock('expo-secure-store', () => ({
  getItemAsync: jest.fn(async (key: string) => mockSecureStore.get(key) ?? null),
  setItemAsync: jest.fn(async (key: string, value: string) => {
    mockSecureStore.set(key, value);
  }),
  deleteItemAsync: jest.fn(async (key: string) => {
    mockSecureStore.delete(key);
  }),
}));

const mockTakeStoredPushToken = jest.fn<Promise<string | null>, []>();

jest.mock('../push', () => ({
  takeStoredPushToken: () => mockTakeStoredPushToken(),
}));

// Imported after the mocks so the store picks them up.
// eslint-disable-next-line import/first
import { request } from '../api/client';
// eslint-disable-next-line import/first
import { useSession } from '../session';

const OLD_SERVER = 'https://lama.contoh.id/api/v1';
const NEW_SERVER = 'https://baru.contoh.id/api/v1';
const USER = { id: 7, name: 'Pekerja', email: 'pekerja@contoh.id' } as User;

type FetchCall = { url: string; method: string; authorization: string | null; body: unknown };

let fetchCalls: FetchCall[] = [];
let respond: (call: FetchCall) => Promise<Response>;

function jsonResponse(status: number, body: unknown): Response {
  return { ok: status >= 200 && status < 300, status, text: async () => JSON.stringify(body) } as Response;
}

/** Let fire-and-forget work (the background revoke) reach fetch. */
async function flush(): Promise<void> {
  for (let i = 0; i < 5; i++) {
    await Promise.resolve();
  }
}

async function signInOn(server: string): Promise<void> {
  useSession.setState({ baseUrl: server });
  await useSession.getState().signIn('sesi-token', USER);
}

beforeEach(() => {
  mockSecureStore.clear();
  fetchCalls = [];
  respond = async () => jsonResponse(200, { message: 'ok' });
  mockTakeStoredPushToken.mockReset().mockResolvedValue('ExponentPushToken[perangkat]');
  useSession.setState({ status: 'signedOut', token: null, user: null, baseUrl: OLD_SERVER });

  globalThis.fetch = jest.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
    const headers = (init?.headers ?? {}) as Record<string, string>;
    const call: FetchCall = {
      url: String(input),
      method: init?.method ?? 'GET',
      authorization: headers.Authorization ?? null,
      body: init?.body ? JSON.parse(String(init.body)) : null,
    };
    fetchCalls.push(call);
    // Like the real fetch, give up when the request times out (AbortController in request()).
    const aborted = new Promise<never>((_, reject) => {
      init?.signal?.addEventListener('abort', () => reject(Object.assign(new Error('Aborted'), { name: 'AbortError' })));
    });
    return Promise.race([respond(call), aborted]);
  }) as typeof fetch;
});

describe('sign in and restore', () => {
  it('keeps the session in secure storage across app launches', async () => {
    await signInOn(OLD_SERVER);
    await useSession.getState().setBaseUrl(OLD_SERVER);

    useSession.setState({ status: 'loading', token: null, user: null, baseUrl: '' });
    await useSession.getState().hydrate();

    expect(useSession.getState()).toMatchObject({ status: 'signedIn', token: 'sesi-token', user: USER, baseUrl: OLD_SERVER });
  });

  it('starts signed out when the stored user is unreadable', async () => {
    mockSecureStore.set('ontime.token', 'sesi-token');
    mockSecureStore.set('ontime.user', '{rusak');

    await useSession.getState().hydrate();

    expect(useSession.getState()).toMatchObject({ status: 'signedOut', token: null, user: null });
  });
});

describe('sign out', () => {
  it('clears the session on the device without waiting for the server', async () => {
    jest.useFakeTimers();
    try {
      await signInOn(OLD_SERVER);
      respond = () => new Promise(() => {}); // offline: the revoke never answers

      await useSession.getState().signOut();

      expect(useSession.getState()).toMatchObject({ status: 'signedOut', token: null, user: null });
      expect(mockSecureStore.has('ontime.token')).toBe(false);
      expect(mockSecureStore.has('ontime.user')).toBe(false);

      // The background revoke gives up after its 10 s timeout without touching the session.
      await flush();
      jest.advanceTimersByTime(10000);
      await flush();
      expect(useSession.getState().status).toBe('signedOut');
    } finally {
      jest.useRealTimers();
    }
  });

  it('revokes the session and this device push token in one request', async () => {
    await signInOn(OLD_SERVER);

    await useSession.getState().signOut();
    await flush();

    expect(fetchCalls).toEqual([
      {
        url: `${OLD_SERVER}/auth/logout`,
        method: 'POST',
        authorization: 'Bearer sesi-token',
        body: { push_token: 'ExponentPushToken[perangkat]' },
      },
    ]);
  });

  it('revokes without a push token when none was registered', async () => {
    mockTakeStoredPushToken.mockResolvedValue(null);
    await signInOn(OLD_SERVER);

    await useSession.getState().signOut();
    await flush();

    expect(fetchCalls).toHaveLength(1);
    expect(fetchCalls[0].body).toEqual({});
  });

  it('revokes once when several sign-outs overlap', async () => {
    await signInOn(OLD_SERVER);

    await Promise.all([useSession.getState().signOut(), useSession.getState().signOut(), useSession.getState().signOut()]);
    await flush();

    expect(fetchCalls.filter((call) => call.url.endsWith('/auth/logout'))).toHaveLength(1);
    expect(mockTakeStoredPushToken).toHaveBeenCalledTimes(1);
  });

  it('stays signed out when the revoke fails', async () => {
    await signInOn(OLD_SERVER);
    respond = async () => {
      throw new TypeError('Network request failed');
    };

    await expect(useSession.getState().signOut()).resolves.toBeUndefined();
    await flush();

    expect(useSession.getState().status).toBe('signedOut');
  });

  it('does not loop when the revoke itself is refused with 401', async () => {
    await signInOn(OLD_SERVER);
    respond = async () => jsonResponse(401, { message: 'Unauthenticated.' });

    await useSession.getState().signOut();
    await flush();

    expect(fetchCalls).toHaveLength(1);
    expect(useSession.getState().status).toBe('signedOut');
  });
});

describe('a refused token', () => {
  it('signs out without trying to revoke a token the server already refused', async () => {
    await signInOn(OLD_SERVER);
    respond = async () => jsonResponse(401, { message: 'Unauthenticated.' });

    await expect(request('/auth/me')).rejects.toMatchObject({ status: 401 });
    await flush();

    expect(useSession.getState()).toMatchObject({ status: 'signedOut', token: null });
    expect(fetchCalls.map((call) => call.url)).toEqual([`${OLD_SERVER}/auth/me`]);
  });

  it('keeps the session when the login call itself answers 401', async () => {
    await signInOn(OLD_SERVER);
    respond = async () => jsonResponse(401, { message: 'Credentials do not match our records.' });

    await expect(request('/auth/login', { method: 'POST', json: {}, skipAuthHandling: true })).rejects.toMatchObject({ status: 401 });

    expect(useSession.getState().status).toBe('signedIn');
  });
});

describe('changing the server address', () => {
  it('signs out and revokes on the old server, never sending the token to the new one', async () => {
    await signInOn(OLD_SERVER);

    await useSession.getState().setBaseUrl('https://baru.contoh.id');
    await flush();
    await request('/auth/me').catch(() => undefined);

    expect(useSession.getState()).toMatchObject({ status: 'signedOut', token: null, baseUrl: NEW_SERVER });
    expect(fetchCalls[0]).toMatchObject({ url: `${OLD_SERVER}/auth/logout`, authorization: 'Bearer sesi-token' });
    expect(fetchCalls.filter((call) => call.url.startsWith(NEW_SERVER)).every((call) => call.authorization === null)).toBe(true);
  });

  it('keeps the session when the same server is saved again', async () => {
    await signInOn(OLD_SERVER);

    await useSession.getState().setBaseUrl('https://lama.contoh.id/');

    expect(useSession.getState()).toMatchObject({ status: 'signedIn', token: 'sesi-token', baseUrl: OLD_SERVER });
    expect(fetchCalls).toHaveLength(0);
  });

  it('just switches the server when nobody is signed in', async () => {
    await useSession.getState().setBaseUrl('https://baru.contoh.id');

    expect(useSession.getState().baseUrl).toBe(NEW_SERVER);
    expect(fetchCalls).toHaveLength(0);
    expect(mockSecureStore.get('ontime.baseUrl')).toBe(NEW_SERVER);
  });
});
