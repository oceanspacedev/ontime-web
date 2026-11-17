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
import { applyCurrentUser, fetchCurrentUser } from '../../hooks/useRefreshUser';
// eslint-disable-next-line import/first
import { PRODUCTION_API_URL, resolveApiTarget, useSession } from '../session';

const OLD_SERVER = 'https://lama.contoh.id/api/v1';
const NEW_SERVER = 'https://baru.contoh.id/api/v1';
const STAGING_SERVER = 'https://staging.contoh.id/api/v1';
const USER = { id: 7, name: 'Pekerja', email: 'pekerja@contoh.id' } as User;
const OTHER_USER = { id: 8, name: 'Pekerja Lain', email: 'lain@contoh.id' } as User;

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

const originalEnv = { apiUrl: process.env.EXPO_PUBLIC_API_URL, appEnv: process.env.EXPO_PUBLIC_APP_ENV };

/** The build environment Expo would inline; undefined removes the variable. */
function buildEnv(apiUrl: string | undefined, appEnv?: string): void {
  for (const [key, value] of [
    ['EXPO_PUBLIC_API_URL', apiUrl],
    ['EXPO_PUBLIC_APP_ENV', appEnv],
  ] as const) {
    if (value === undefined) {
      delete process.env[key];
    } else {
      process.env[key] = value;
    }
  }
}

afterAll(() => {
  buildEnv(originalEnv.apiUrl, originalEnv.appEnv);
});

beforeEach(() => {
  buildEnv(STAGING_SERVER);
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

    useSession.setState({ status: 'loading', token: null, user: null, baseUrl: '' });
    await useSession.getState().hydrate();

    expect(useSession.getState()).toMatchObject({ status: 'signedIn', token: 'sesi-token', user: USER, baseUrl: STAGING_SERVER });
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

  it('keeps the next account signed in when the previous account request answers 401 late', async () => {
    await signInOn(OLD_SERVER);
    let answerA: (response: Response) => void = () => {};
    respond = () =>
      new Promise((resolve) => {
        answerA = resolve;
      });

    const requestA = request('/attendances');
    await flush();
    await useSession.getState().signOut({ revoke: false });
    await useSession.getState().signIn('sesi-b', OTHER_USER);

    answerA(jsonResponse(401, { message: 'Unauthenticated.' }));
    await expect(requestA).rejects.toMatchObject({ status: 401 });
    await flush();

    expect(fetchCalls[0].authorization).toBe('Bearer sesi-token');
    expect(useSession.getState()).toMatchObject({ status: 'signedIn', token: 'sesi-b', user: OTHER_USER });
  });

  it('keeps the session when the login call itself answers 401', async () => {
    await signInOn(OLD_SERVER);
    respond = async () => jsonResponse(401, { message: 'Credentials do not match our records.' });

    await expect(request('/auth/login', { method: 'POST', json: {}, skipAuthHandling: true })).rejects.toMatchObject({ status: 401 });

    expect(useSession.getState().status).toBe('signedIn');
  });
});

describe('server address per build', () => {
  it('uses the address the build was made with', async () => {
    buildEnv(`${STAGING_SERVER}/`, 'preview');
    await useSession.getState().hydrate();
    await request('/auth/me').catch(() => undefined);

    expect(useSession.getState()).toMatchObject({ baseUrl: STAGING_SERVER, apiError: null });
    expect(fetchCalls[0].url).toBe(`${STAGING_SERVER}/auth/me`);
  });

  it('falls back to production only in a production build', () => {
    expect(resolveApiTarget({ apiUrl: undefined, appEnv: 'production' })).toEqual({ url: PRODUCTION_API_URL, production: true, error: null });
    expect(PRODUCTION_API_URL).toBe('https://ontime.oceanspace.co.id/api/v1');
  });

  it.each([['preview'], ['development'], [undefined]])('refuses to talk to any server when a %s build has no address', async (appEnv) => {
    buildEnv(undefined, appEnv);
    mockSecureStore.set('ontime.token', 'sesi-token');
    mockSecureStore.set('ontime.user', JSON.stringify(USER));

    await useSession.getState().hydrate();

    expect(useSession.getState()).toMatchObject({ status: 'signedOut', token: null, baseUrl: null });
    expect(useSession.getState().apiError).toContain('EXPO_PUBLIC_API_URL');
    await expect(request('/auth/login', { method: 'POST', json: {}, skipAuthHandling: true })).rejects.toThrow('Alamat server aplikasi belum diatur');
    expect(fetchCalls).toEqual([]);
  });

  it('accepts plain http only for a local server', () => {
    expect(resolveApiTarget({ apiUrl: 'http://127.0.0.1:8000/api/v1' }).url).toBe('http://127.0.0.1:8000/api/v1');
    expect(resolveApiTarget({ apiUrl: 'http://10.0.2.2:8000/api/v1' }).url).toBe('http://10.0.2.2:8000/api/v1');
    expect(resolveApiTarget({ apiUrl: 'http://ontime.test/api/v1' }).url).toBe('http://ontime.test/api/v1');
    expect(resolveApiTarget({ apiUrl: 'http://staging.contoh.id/api/v1' })).toMatchObject({ url: null, error: expect.stringContaining('https') });
    expect(resolveApiTarget({ apiUrl: 'staging.contoh.id' })).toMatchObject({ url: null, error: expect.stringContaining('tidak valid') });
  });

  it('sends the production build to https://ontime.oceanspace.co.id', () => {
    // eas.json's production profile is the only place the store build gets its host from.
    const eas = require('../../../eas.json');
    expect(eas.build.production.env.EXPO_PUBLIC_APP_ENV).toBe('production');
    expect(eas.build.production.env.EXPO_PUBLIC_API_URL).toBe('https://ontime.oceanspace.co.id/api/v1');
    expect(PRODUCTION_API_URL).toBe('https://ontime.oceanspace.co.id/api/v1');
    expect(resolveApiTarget({ apiUrl: eas.build.production.env.EXPO_PUBLIC_API_URL, appEnv: 'production' })).toEqual({ url: PRODUCTION_API_URL, production: true, error: null });
    expect(resolveApiTarget({ appEnv: 'production' }).url).toBe(PRODUCTION_API_URL);
  });

  it('marks only the production address as production', () => {
    expect(resolveApiTarget({ apiUrl: STAGING_SERVER, appEnv: 'production' }).production).toBe(false);
    expect(resolveApiTarget({ apiUrl: PRODUCTION_API_URL }).production).toBe(true);
  });

  it('ignores and erases an address saved by an older build, keeping the session', async () => {
    mockSecureStore.set('ontime.baseUrl', NEW_SERVER);
    mockSecureStore.set('ontime.token', 'sesi-token');
    mockSecureStore.set('ontime.user', JSON.stringify(USER));

    await useSession.getState().hydrate();

    expect(useSession.getState()).toMatchObject({ status: 'signedIn', token: 'sesi-token', baseUrl: STAGING_SERVER });
    expect(mockSecureStore.has('ontime.baseUrl')).toBe(false);
  });
});

describe('refreshing the signed-in user', () => {
  it('keeps the new account when the previous account /me answers after switching', async () => {
    await signInOn(OLD_SERVER);
    let answerA: (response: Response) => void = () => {};
    respond = () =>
      new Promise((resolve) => {
        answerA = resolve;
      });

    const refreshA = fetchCurrentUser();
    await flush();
    await useSession.getState().signOut({ revoke: false });
    await useSession.getState().signIn('sesi-b', OTHER_USER);

    answerA(jsonResponse(200, { user: USER }));
    await applyCurrentUser(await refreshA);

    expect(useSession.getState()).toMatchObject({ status: 'signedIn', token: 'sesi-b', user: OTHER_USER });
    expect(JSON.parse(mockSecureStore.get('ontime.user') ?? 'null')).toEqual(OTHER_USER);
  });

  it('does not store a late answer once signed out', async () => {
    await signInOn(OLD_SERVER);
    const epoch = useSession.getState().epoch;
    await useSession.getState().signOut({ revoke: false });

    await useSession.getState().setUser(USER, epoch);
    await useSession.getState().setUser(USER);

    expect(useSession.getState()).toMatchObject({ status: 'signedOut', user: null });
    expect(mockSecureStore.has('ontime.user')).toBe(false);
  });

  it('stores the answer for the session that asked', async () => {
    await signInOn(OLD_SERVER);
    const renamed = { ...USER, name: 'Pekerja Baru' };
    respond = async () => jsonResponse(200, { user: renamed });

    await applyCurrentUser(await fetchCurrentUser());

    expect(useSession.getState().user).toEqual(renamed);
    expect(JSON.parse(mockSecureStore.get('ontime.user') ?? 'null')).toEqual(renamed);
  });
});
