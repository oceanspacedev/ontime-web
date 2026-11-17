/// <reference types="jest" />
import { MutationObserver, onlineManager, QueryClient, QueryObserver } from '@tanstack/react-query';
import * as Location from 'expo-location';

import { checkMutationOptions, freshCheckPosition, submitCheck } from '../api/attendance';
import { configureApi, NetworkError } from '../api/client';
import { type DevicePosition, LocationServicesOffError, readDevicePosition, SUBMIT_LAST_KNOWN_MAX_AGE_MS } from '../location';

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

function okResponse(): Response {
  const body = { message: 'Absen masuk berhasil.', attendance: { id: 1, status: 'present' } };
  return { ok: true, status: 200, text: async () => JSON.stringify(body) } as Response;
}

let fetchMock: jest.Mock;

beforeEach(() => {
  configureApi({ getBaseUrl: () => 'https://ontime.test/api/v1', getToken: () => 'token', onUnauthenticated: () => {} });
  fetchMock = jest.fn(async () => okResponse());
  globalThis.fetch = fetchMock as unknown as typeof fetch;
  onlineManager.setOnline(true);
});

afterEach(() => {
  onlineManager.setOnline(true);
});

describe('attendance check while offline (RSK-10)', () => {
  it('fails at once instead of pausing, and never sends the check in later', async () => {
    const client = new QueryClient();
    const buildPayload = jest.fn(async () => ({ latitude: -6.2, longitude: 106.8 }));
    const observer = new MutationObserver(client, {
      ...checkMutationOptions,
      mutationFn: () => submitCheck('check_in', buildPayload),
    });
    onlineManager.setOnline(false);

    const error = await observer.mutate().catch((caught: unknown) => caught);

    expect(error).toBeInstanceOf(NetworkError);
    expect((error as Error).message).toBe(OFFLINE_MESSAGE);
    expect(observer.getCurrentResult().isError).toBe(true);
    expect(observer.getCurrentResult().isPaused).toBe(false);
    // Offline the GPS and photos are not even prepared.
    expect(buildPayload).not.toHaveBeenCalled();

    // Back online: nothing was queued, so nothing is sent behind the worker's back.
    onlineManager.setOnline(true);
    await client.resumePausedMutations();
    expect(client.getMutationCache().getAll().some((mutation) => mutation.state.isPaused)).toBe(false);
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it('builds the payload at submit time and sends it when online', async () => {
    const buildPayload = jest.fn(async () => ({ latitude: -6.25, longitude: 106.85, accuracy: 12, isMocked: false }));

    await submitCheck('check_out', buildPayload);

    expect(buildPayload).toHaveBeenCalledTimes(1);
    expect(fetchMock).toHaveBeenCalledTimes(1);
    const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit];
    expect(url).toContain('/attendance/check-out');
    const form = init.body as FormData;
    expect(form.get('latitude')).toBe('-6.25');
    expect(form.get('longitude')).toBe('106.85');
  });
});

describe('fresh position at submit (H08)', () => {
  const OLD: DevicePosition = { latitude: -6.2, longitude: 106.8, accuracy: 10, mocked: false };
  const NEW: DevicePosition = { latitude: -6.3, longitude: 106.9, accuracy: 8, mocked: false };
  const UNAVAILABLE = 'Lokasi belum terbaca. Pindah ke area terbuka atau dekat jendela, lalu tekan Ulangi.';
  const key = ['device-location', 1, 'check_in'];
  const locationQuery = (read: () => Promise<DevicePosition>) =>
    ({ queryKey: key, queryFn: read, staleTime: 0, gcTime: 0, retry: false, networkMode: 'always' }) as const;

  it('sends the position read at submit, not the fix cached when the screen opened, and updates the preview', async () => {
    const client = new QueryClient();
    const read = jest.fn().mockResolvedValueOnce(OLD).mockResolvedValueOnce(NEW);
    const preview = new QueryObserver(client, locationQuery(read));
    const unsubscribe = preview.subscribe(() => {});
    await preview.refetch();
    expect(client.getQueryData(key)).toEqual(OLD);

    const payload = await freshCheckPosition(read, (fresh) => client.setQueryData(key, fresh));

    expect(read).toHaveBeenCalledTimes(2);
    expect(payload).toEqual({ latitude: -6.3, longitude: 106.9, accuracy: 8, isMocked: false });
    expect(preview.getCurrentResult().data).toEqual(NEW);
    unsubscribe();
  });

  it('is not held up by the screen refetching its own location query meanwhile', async () => {
    // On a real phone the screen's query refetched while the submit read was running, and the submit
    // spun forever with no request sent. The submit read must not depend on that query.
    const client = new QueryClient();
    let releaseScreenRead: (position: DevicePosition) => void = () => {};
    const screenRead = jest.fn(() => new Promise<DevicePosition>((resolve) => (releaseScreenRead = resolve)));
    const preview = new QueryObserver(client, locationQuery(screenRead));
    const unsubscribe = preview.subscribe(() => {});
    void preview.refetch();

    const payload = freshCheckPosition(async () => NEW, (fresh) => client.setQueryData(key, fresh));
    void preview.refetch({ cancelRefetch: true });

    await expect(payload).resolves.toMatchObject({ latitude: -6.3 });
    releaseScreenRead(OLD);
    unsubscribe();
  });

  it('gives up with the Indonesian message when the read never answers', async () => {
    jest.useFakeTimers();
    try {
      const result = freshCheckPosition(() => new Promise<DevicePosition>(() => {}), undefined, 35000);
      const settled = expect(result).rejects.toThrow(UNAVAILABLE);
      await jest.advanceTimersByTimeAsync(35000);
      await settled;
    } finally {
      jest.useRealTimers();
    }
  });

  it('rejects with the Indonesian message and never returns the cached coordinates when the read fails', async () => {
    const onRead = jest.fn();
    const native = Object.assign(new Error('Current location is unavailable. Make sure that location services are enabled'), {
      code: 'ERR_CURRENT_LOCATION_IS_UNAVAILABLE',
    });

    await expect(freshCheckPosition(() => Promise.reject(native), onRead)).rejects.toThrow(UNAVAILABLE);
    const off = new LocationServicesOffError();
    await expect(freshCheckPosition(() => Promise.reject(off), onRead)).rejects.toBe(off);
    expect(onRead).not.toHaveBeenCalled();
  });

  it('reads GPS even while offline, since it needs no internet', async () => {
    onlineManager.setOnline(false);

    await expect(freshCheckPosition(async () => NEW)).resolves.toMatchObject({ latitude: -6.3 });
  });

  it('accepts only a very recent cached fix when no fresh fix arrives at submit', async () => {
    jest.useFakeTimers();
    try {
      jest.mocked(Location.getForegroundPermissionsAsync).mockResolvedValue({
        granted: true,
        canAskAgain: true,
        android: { accuracy: 'fine' },
      } as unknown as Location.LocationPermissionResponse);
      jest.mocked(Location.hasServicesEnabledAsync).mockResolvedValue(true);
      jest.mocked(Location.getCurrentPositionAsync).mockReturnValue(new Promise(() => {}));
      jest.mocked(Location.getLastKnownPositionAsync).mockResolvedValue(null);

      const read = readDevicePosition({ lastKnownMaxAgeMs: SUBMIT_LAST_KNOWN_MAX_AGE_MS });
      const settled = expect(read).rejects.toThrow(UNAVAILABLE);
      await jest.runAllTimersAsync();
      await settled;

      expect(Location.getLastKnownPositionAsync).toHaveBeenCalledWith(expect.objectContaining({ maxAge: 10000 }));
    } finally {
      jest.useRealTimers();
    }
  });
});

describe('location permission on each read', () => {
  const FIX = { coords: { latitude: -6.2, longitude: 106.8, accuracy: 5 }, mocked: false } as unknown as Location.LocationObject;

  beforeEach(() => {
    jest.mocked(Location.getForegroundPermissionsAsync).mockReset();
    jest.mocked(Location.requestForegroundPermissionsAsync).mockReset();
    jest.mocked(Location.hasServicesEnabledAsync).mockResolvedValue(true);
    jest.mocked(Location.getCurrentPositionAsync).mockResolvedValue(FIX);
  });

  it('does not open the permission request when precise location is already granted', async () => {
    // On Android every request opens the permission activity, which pauses and resumes the app; with
    // the screen reading again on resume this looped forever on a real phone and blocked the submit.
    jest.mocked(Location.getForegroundPermissionsAsync).mockResolvedValue({
      granted: true,
      canAskAgain: true,
      android: { accuracy: 'fine' },
    } as unknown as Location.LocationPermissionResponse);

    await expect(readDevicePosition()).resolves.toMatchObject({ latitude: -6.2 });
    expect(Location.requestForegroundPermissionsAsync).not.toHaveBeenCalled();
  });

  it('asks when not granted yet, and when only approximate location is granted', async () => {
    jest.mocked(Location.getForegroundPermissionsAsync).mockResolvedValueOnce({ granted: false, canAskAgain: true } as unknown as Location.LocationPermissionResponse);
    jest.mocked(Location.requestForegroundPermissionsAsync).mockResolvedValue({
      granted: true,
      canAskAgain: true,
      android: { accuracy: 'fine' },
    } as unknown as Location.LocationPermissionResponse);
    await expect(readDevicePosition()).resolves.toMatchObject({ latitude: -6.2 });

    jest.mocked(Location.getForegroundPermissionsAsync).mockResolvedValueOnce({
      granted: true,
      canAskAgain: true,
      android: { accuracy: 'coarse' },
    } as unknown as Location.LocationPermissionResponse);
    await expect(readDevicePosition()).resolves.toMatchObject({ latitude: -6.2 });

    expect(Location.requestForegroundPermissionsAsync).toHaveBeenCalledTimes(2);
  });
});
