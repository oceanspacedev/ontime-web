import * as SecureStore from 'expo-secure-store';
import { create } from 'zustand';

import { configureApi } from './api/client';
import type { User } from './api/types';
import { unregisterPushToken } from './push';

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
  signOut: () => Promise<void>;
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

  signOut: async () => {
    if (get().status === 'signedOut') {
      return;
    }
    // Needs the session token, so it runs before the token is dropped.
    await unregisterPushToken();
    set({ token: null, user: null, status: 'signedOut' });
    await Promise.all([writeSecure(TOKEN_KEY, null), writeSecure(USER_KEY, null)]);
  },

  setBaseUrl: async (url) => {
    const normalized = normalizeBaseUrl(url);
    set({ baseUrl: normalized });
    await writeSecure(BASE_URL_KEY, normalized);
  },
}));

configureApi({
  getBaseUrl: () => useSession.getState().baseUrl,
  getToken: () => useSession.getState().token,
  onUnauthenticated: () => {
    void useSession.getState().signOut();
  },
});
