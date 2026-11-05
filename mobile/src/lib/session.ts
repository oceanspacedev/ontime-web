import * as SecureStore from 'expo-secure-store';
import { create } from 'zustand';

import { logout } from './api/auth';
import { configureApi } from './api/client';
import type { User } from './api/types';
import { takeStoredPushToken } from './push';

const TOKEN_KEY = 'ontime.token';
const USER_KEY = 'ontime.user';
const BASE_URL_KEY = 'ontime.baseUrl';

export const DEFAULT_BASE_URL = (process.env.EXPO_PUBLIC_API_URL ?? 'http://127.0.0.1:8000/api/v1').trim();

export type SessionStatus = 'loading' | 'signedOut' | 'signedIn';

interface SessionState {
  status: SessionStatus;
  token: string | null;
  user: User | null;
  baseUrl: string;
  hydrate: () => Promise<void>;
  signIn: (token: string, user: User) => Promise<void>;
  setUser: (user: User) => Promise<void>;
  /**
   * Ends the session on this device right away, then (unless revoke is false) revokes it on the
   * server in the background. Pass revoke: false when the server already refused the token.
   */
  signOut: (options?: { revoke?: boolean }) => Promise<void>;
  setBaseUrl: (url: string) => Promise<void>;
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

export function normalizeBaseUrl(input: string): string {
  let url = input.trim().replace(/\/+$/, '');
  if (!/^https?:\/\//i.test(url)) {
    url = `http://${url}`;
  }
  if (!/\/api\/v1$/i.test(url)) {
    url = `${url}/api/v1`;
  }
  return url;
}

export const useSession = create<SessionState>((set, get) => ({
  status: 'loading',
  token: null,
  user: null,
  baseUrl: DEFAULT_BASE_URL,

  hydrate: async () => {
    const [token, rawUser, baseUrl] = await Promise.all([
      readSecure(TOKEN_KEY),
      readSecure(USER_KEY),
      readSecure(BASE_URL_KEY),
    ]);
    let user: User | null = null;
    if (rawUser) {
      try {
        user = JSON.parse(rawUser) as User;
      } catch {
        user = null;
      }
    }
    set({
      baseUrl: baseUrl || DEFAULT_BASE_URL,
      token: token && user ? token : null,
      user: token && user ? user : null,
      status: token && user ? 'signedIn' : 'signedOut',
    });
  },

  signIn: async (token, user) => {
    set({ token, user, status: 'signedIn' });
    await Promise.all([writeSecure(TOKEN_KEY, token), writeSecure(USER_KEY, JSON.stringify(user))]);
  },

  setUser: async (user) => {
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
    set({ token: null, user: null, status: 'signedOut' });

    const [pushToken] = await Promise.all([takeStoredPushToken(), writeSecure(TOKEN_KEY, null), writeSecure(USER_KEY, null)]);

    if (revoke && token) {
      // Captured token and server: the session is already gone locally and the server may change next.
      logout({ token, baseUrl }, pushToken).catch(() => {
        // Offline or already revoked; the token also ends by itself after long disuse.
      });
    }
  },

  setBaseUrl: async (url) => {
    const normalized = normalizeBaseUrl(url);
    // A session belongs to the server that issued it; never send its token to another one.
    if (normalized !== get().baseUrl && get().status === 'signedIn') {
      await get().signOut();
    }
    set({ baseUrl: normalized });
    await writeSecure(BASE_URL_KEY, normalized);
  },
}));

configureApi({
  getBaseUrl: () => useSession.getState().baseUrl,
  getToken: () => useSession.getState().token,
  onUnauthenticated: () => {
    void useSession.getState().signOut({ revoke: false });
  },
});
