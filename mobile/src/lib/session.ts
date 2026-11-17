import * as SecureStore from 'expo-secure-store';
import { create } from 'zustand';

import { logout } from './api/auth';
import { configureApi } from './api/client';
import type { User } from './api/types';
import { forgetPushUser } from './onesignal';
import { takeStoredPushToken } from './push';

const TOKEN_KEY = 'ontime.token';
const USER_KEY = 'ontime.user';
/** Saved by older builds that let the user type a server address; it is erased and never read again. */
const LEGACY_BASE_URL_KEY = 'ontime.baseUrl';

/** The production API (API.md). A build reaches it only when it says so; see resolveApiTarget. */
export const PRODUCTION_API_URL = 'https://ontime.oceanspace.co.id/api/v1';

/** Where this build sends its requests, or why it refuses to send any. */
export type ApiTarget = { url: string; production: boolean; error: null } | { url: null; production: false; error: string };

/** Hosts a debug build may reach over plain http: this machine, the Android emulator's host and private networks. */
const LOCAL_HOST = /^(localhost|127(?:\.\d{1,3}){3}|10(?:\.\d{1,3}){3}|192\.168(?:\.\d{1,3}){2}|172\.(?:1[6-9]|2\d|3[01])(?:\.\d{1,3}){2}|[a-z0-9.-]+\.(?:local|localhost|test))$/i;

/**
 * Picks the API host from the build's environment (EXPO_PUBLIC_API_URL, inlined by Expo when the
 * bundle is made). The production host is used without that variable only when the build declares
 * itself production (EXPO_PUBLIC_APP_ENV=production, set by the production profile in eas.json),
 * so a preview or development build missing its host refuses to run instead of writing to production.
 */
export function resolveApiTarget(env: { apiUrl?: string; appEnv?: string }): ApiTarget {
  const configured = (env.apiUrl ?? '').trim().replace(/\/+$/, '');
  const productionBuild = (env.appEnv ?? '').trim() === 'production';
  const url = configured || (productionBuild ? PRODUCTION_API_URL : '');

  if (!url) {
    return {
      url: null,
      production: false,
      error: 'Alamat server belum diatur untuk build ini (EXPO_PUBLIC_API_URL). Aplikasi tidak terhubung ke server mana pun agar tidak menulis ke server produksi.',
    };
  }

  const match = /^(https?):\/\/([^/:?#]+)(?::\d+)?(?:\/[^?#]*)?$/i.exec(url);
  if (!match) {
    return { url: null, production: false, error: `Alamat server tidak valid: ${url}. Gunakan alamat lengkap, misalnya https://contoh.id/api/v1.` };
  }
  if (match[1].toLowerCase() === 'http' && !LOCAL_HOST.test(match[2])) {
    return { url: null, production: false, error: `Alamat server ${url} harus memakai https. Http hanya boleh untuk server lokal.` };
  }

  return { url, production: url.toLowerCase() === PRODUCTION_API_URL, error: null };
}

/** Read on every call so tests can change it; in the app bundle Expo replaces both with constants. */
export function currentApiTarget(): ApiTarget {
  return resolveApiTarget({ apiUrl: process.env.EXPO_PUBLIC_API_URL, appEnv: process.env.EXPO_PUBLIC_APP_ENV });
}

export type SessionStatus = 'loading' | 'signedOut' | 'signedIn';

interface SessionState {
  status: SessionStatus;
  token: string | null;
  user: User | null;
  /** Null when this build has no usable API host; apiError then says why. */
  baseUrl: string | null;
  apiError: string | null;
  /** Bumped on every sign-in and sign-out, so work started for one session cannot land in the next. */
  epoch: number;
  hydrate: () => Promise<void>;
  signIn: (token: string, user: User) => Promise<void>;
  /**
   * Replaces the signed-in user. Ignored when signed out, or when `epoch` (read when the request
   * started) belongs to an earlier session: a late /me answer must not overwrite the next account.
   */
  setUser: (user: User, epoch?: number) => Promise<void>;
  /**
   * Ends the session on this device right away, then (unless revoke is false) revokes it on the
   * server in the background. Pass revoke: false when the server already refused the token.
   */
  signOut: (options?: { revoke?: boolean }) => Promise<void>;
}

async function readSecure(key: string): Promise<string | null> {
  try {
    return await SecureStore.getItemAsync(key);
  } catch {
    return null;
  }
}

async function writeSecure(key: string, value: string | null): Promise<void> {
  try {
    if (value === null) {
      await SecureStore.deleteItemAsync(key);
    } else {
      await SecureStore.setItemAsync(key, value);
    }
  } catch {
    // Secure storage can fail on some emulators; the session then lasts until the app restarts.
  }
}

const initialTarget = currentApiTarget();

export const useSession = create<SessionState>((set, get) => ({
  status: 'loading',
  token: null,
  user: null,
  baseUrl: initialTarget.url,
  apiError: initialTarget.error,
  epoch: 0,

  hydrate: async () => {
    const target = currentApiTarget();
    const [token, rawUser] = await Promise.all([readSecure(TOKEN_KEY), readSecure(USER_KEY), writeSecure(LEGACY_BASE_URL_KEY, null)]);
    let user: User | null = null;
    if (rawUser) {
      try {
        user = JSON.parse(rawUser) as User;
      } catch {
        user = null;
      }
    }
    // Without a usable host the session is not restored: the login screen explains what is missing.
    const restored = Boolean(token && user && target.url);
    set({
      baseUrl: target.url,
      apiError: target.error,
      token: restored ? token : null,
      user: restored ? user : null,
      status: restored ? 'signedIn' : 'signedOut',
      epoch: get().epoch + 1,
    });
  },

  signIn: async (token, user) => {
    set({ token, user, status: 'signedIn', epoch: get().epoch + 1 });
    await Promise.all([writeSecure(TOKEN_KEY, token), writeSecure(USER_KEY, JSON.stringify(user))]);
  },

  setUser: async (user, epoch) => {
    const current = get();
    if (current.status !== 'signedIn' || (epoch !== undefined && epoch !== current.epoch)) {
      return;
    }
    set({ user });
    await writeSecure(USER_KEY, JSON.stringify(user));
  },

  signOut: async ({ revoke = true } = {}) => {
    const { status, token, baseUrl } = get();
    if (status === 'signedOut') {
      return;
    }
    // Cleared before anything is awaited, so overlapping calls (several 401s at once) do it once
    // and the user never waits on the network to leave.
    set({ token: null, user: null, status: 'signedOut', epoch: get().epoch + 1 });
    // This device stops receiving the signed-out user's pushes right away.
    forgetPushUser();

    const [pushToken] = await Promise.all([takeStoredPushToken(), writeSecure(TOKEN_KEY, null), writeSecure(USER_KEY, null)]);

    if (revoke && token && baseUrl) {
      // Captured token and server: the session is already gone locally and the server may change next.
      logout({ token, baseUrl }, pushToken).catch(() => {
        // Offline or already revoked; the token also ends by itself after long disuse.
      });
    }
  },
}));

configureApi({
  getBaseUrl: () => useSession.getState().baseUrl,
  getToken: () => useSession.getState().token,
  onUnauthenticated: () => {
    void useSession.getState().signOut({ revoke: false });
  },
});
