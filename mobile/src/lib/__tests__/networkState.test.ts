/// <reference types="jest" />
import { MutationObserver, onlineManager, QueryClient } from '@tanstack/react-query';

import { checkMutationOptions, submitCheck } from '../api/attendance';
import { configureApi, NetworkError, request } from '../api/client';
import {
  confirmOnline,
  isOfflineState,
  isOnlineRead,
  type NetworkReader,
  RECENT_OFFLINE_EVENT_MS,
  recordNetworkEvent,
  refreshOnlineState,
  resetNetworkEvents,
  setNetworkReader,
} from '../networkState';

jest.mock('expo-location', () => ({
  Accuracy: { High: 4 },
  getForegroundPermissionsAsync: jest.fn(),
  requestForegroundPermissionsAsync: jest.fn(),
  hasServicesEnabledAsync: jest.fn(),
  getCurrentPositionAsync: jest.fn(),
  getLastKnownPositionAsync: jest.fn(),
}));
jest.mock('react-native', () => ({ Platform: { OS: 'android' }, Linking: {} }));

const OFFLINE_MESSAGE = 'Tidak ada koneksi internet. Periksa koneksi Anda lalu coba lagi.';

function okResponse(body: unknown = { message: 'ok' }): Response {
  return { ok: true, status: 200, text: async () => JSON.stringify(body) } as Response;
}

const online: NetworkReader = async () => ({ isConnected: true, isInternetReachable: true });
const offline: NetworkReader = async () => ({ isConnected: true, isInternetReachable: false });

let fetchMock: jest.Mock;

beforeEach(() => {
  configureApi({ getBaseUrl: () => 'https://ontime.test/api/v1', getToken: () => 'token', onUnauthenticated: () => {} });
  fetchMock = jest.fn(async () => okResponse());
  globalThis.fetch = fetchMock as unknown as typeof fetch;
  onlineManager.setOnline(true);
  setNetworkReader(null);
  resetNetworkEvents();
});

afterEach(() => {
  onlineManager.setOnline(true);
  setNetworkReader(null);
});

describe('isOfflineState', () => {
  it('is offline only when the device says so', () => {
    expect(isOfflineState({ isConnected: false, isInternetReachable: true })).toBe(true);
    expect(isOfflineState({ isConnected: true, isInternetReachable: false })).toBe(true);
    expect(isOfflineState({ isConnected: true, isInternetReachable: true })).toBe(false);
  });

  it('counts an unknown state as online', () => {
    expect(isOfflineState({})).toBe(false);
    expect(isOfflineState({ isConnected: true })).toBe(false);
  });
});

describe('isOnlineRead', () => {
  it('does not let a read undo an offline event from the last moments (a just-lost network can still read as connected)', () => {
    recordNetworkEvent({ isConnected: false, isInternetReachable: false }, 10_000);

    expect(isOnlineRead({ isConnected: true, isInternetReachable: true }, 10_000 + RECENT_OFFLINE_EVENT_MS - 1)).toBe(false);
    expect(isOnlineRead({ isConnected: true, isInternetReachable: true }, 10_000 + RECENT_OFFLINE_EVENT_MS)).toBe(true);
  });

  it('is not held back by an online event', () => {
    recordNetworkEvent({ isConnected: true, isInternetReachable: true }, 10_000);

    expect(isOnlineRead({ isConnected: true, isInternetReachable: true }, 10_001)).toBe(true);
  });

  it('reports a read that says offline as offline', () => {
    expect(isOnlineRead({ isConnected: true, isInternetReachable: false })).toBe(false);
  });
});

describe('refreshOnlineState', () => {
  it('stores what the device reports now', async () => {
    onlineManager.setOnline(false);
    await expect(refreshOnlineState(online)).resolves.toBe(true);
    expect(onlineManager.isOnline()).toBe(true);

    await expect(refreshOnlineState(offline)).resolves.toBe(false);
    expect(onlineManager.isOnline()).toBe(false);
  });

  it('leaves the stored value alone when the device cannot be read', async () => {
    onlineManager.setOnline(false);
    await expect(refreshOnlineState(async () => Promise.reject(new Error('native gone')))).resolves.toBeNull();
    await expect(refreshOnlineState(null)).resolves.toBeNull();
    expect(onlineManager.isOnline()).toBe(false);
  });
});

describe('confirmOnline', () => {
  it('answers online without asking the device', async () => {
    const reader = jest.fn(online);
    await expect(confirmOnline(reader)).resolves.toBe(true);
    expect(reader).not.toHaveBeenCalled();
  });

  it('asks the device when the stored value says offline', async () => {
    onlineManager.setOnline(false);
    await expect(confirmOnline(online)).resolves.toBe(true);
    expect(onlineManager.isOnline()).toBe(true);
  });

  it('keeps a fresh offline event over a read that still says online, so a truly offline submit is refused', async () => {
    recordNetworkEvent({ isConnected: false, isInternetReachable: false });
    onlineManager.setOnline(false);

    await expect(confirmOnline(online)).resolves.toBe(false);
    expect(onlineManager.isOnline()).toBe(false);
  });

  it('stays offline when the device agrees, cannot be read, or there is no reader', async () => {
    onlineManager.setOnline(false);
    await expect(confirmOnline(offline)).resolves.toBe(false);
    await expect(confirmOnline(async () => Promise.reject(new Error('native gone')))).resolves.toBe(false);
    await expect(confirmOnline(null)).resolves.toBe(false);
    expect(onlineManager.isOnline()).toBe(false);
  });
});

describe('a submit while the stored state is a stale "offline"', () => {
  it('reaches the server once the device says it is online (the Xiaomi 17 WhatsApp login)', async () => {
    setNetworkReader(online);
    onlineManager.setOnline(false);

    await expect(request('/auth/otp/request', { method: 'POST', json: { channel: 'whatsapp', phone: '087864322667' } })).resolves.toEqual({ message: 'ok' });

    expect(fetchMock).toHaveBeenCalledTimes(1);
    expect(onlineManager.isOnline()).toBe(true);
  });

  it('still fails at once, without sending, when the device confirms it is offline', async () => {
    setNetworkReader(offline);
    onlineManager.setOnline(false);

    const error = await request('/auth/otp/verify', { method: 'POST', json: { challenge_id: 'x', code: '123456' } }).catch((caught: unknown) => caught);

    expect(error).toBeInstanceOf(NetworkError);
    expect((error as NetworkError).kind).toBe('offline');
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it('does not ask the device for a GET, which React Query already pauses while offline', async () => {
    const reader = jest.fn(online);
    setNetworkReader(reader);
    onlineManager.setOnline(false);

    await request('/me');

    expect(reader).not.toHaveBeenCalled();
    expect(fetchMock).toHaveBeenCalledTimes(1);
  });

  it('lets a check in through and builds its payload once the device says it is online', async () => {
    setNetworkReader(online);
    onlineManager.setOnline(false);
    const buildPayload = jest.fn(async () => ({ latitude: -6.2, longitude: 106.8 }));
    fetchMock.mockResolvedValue(okResponse({ message: 'Absen masuk berhasil.', attendance: { id: 1, status: 'present' } }));

    const observer = new MutationObserver(new QueryClient(), {
      ...checkMutationOptions,
      mutationFn: () => submitCheck('check_in', buildPayload),
    });
    await observer.mutate();

    expect(buildPayload).toHaveBeenCalledTimes(1);
    expect(fetchMock).toHaveBeenCalledTimes(1);
  });

  it('refuses a check in before preparing GPS or photos when the device confirms it is offline', async () => {
    setNetworkReader(offline);
    onlineManager.setOnline(false);
    const buildPayload = jest.fn(async () => ({ latitude: -6.2, longitude: 106.8 }));

    const error = await submitCheck('check_in', buildPayload).catch((caught: unknown) => caught);

    expect(error).toBeInstanceOf(NetworkError);
    expect((error as Error).message).toBe(OFFLINE_MESSAGE);
    expect(buildPayload).not.toHaveBeenCalled();
    expect(fetchMock).not.toHaveBeenCalled();
  });
});
