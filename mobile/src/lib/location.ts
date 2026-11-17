import * as Location from 'expo-location';
import { Linking, Platform } from 'react-native';

/** The user refused the location permission. */
export class LocationDeniedError extends Error {
  canAskAgain: boolean;

  constructor(canAskAgain: boolean) {
    super('Izin lokasi ditolak. Presensi butuh lokasi, aktifkan izin lokasi untuk aplikasi ini.');
    this.name = 'LocationDeniedError';
    this.canAskAgain = canAskAgain;
  }
}

/** Android granted only "approximate" location, which is too coarse for the office geofence. */
export class ApproximateLocationError extends Error {
  canAskAgain: boolean;

  constructor(canAskAgain: boolean) {
    super('Lokasi akurat belum diizinkan. Aktifkan "Gunakan lokasi akurat" untuk OnTime.');
    this.name = 'ApproximateLocationError';
    this.canAskAgain = canAskAgain;
  }
}

/** Device location (GPS) is switched off, or the high accuracy mode was refused. */
export class LocationServicesOffError extends Error {
  constructor() {
    super('GPS/lokasi perangkat mati. Aktifkan lokasi lalu tekan Ulangi.');
    this.name = 'LocationServicesOffError';
  }
}

/** No fix arrived in time and the phone has no recent position to fall back on. */
export class LocationUnavailableError extends Error {
  constructor() {
    super('Lokasi belum terbaca. Pindah ke area terbuka atau dekat jendela, lalu tekan Ulangi.');
    this.name = 'LocationUnavailableError';
  }
}

export interface DevicePosition {
  latitude: number;
  longitude: number;
  /** Accuracy radius in metres, when the OS reports it. */
  accuracy: number | null;
  /** The OS says the position comes from a mock provider or a spoofing tool. */
  mocked: boolean;
}

const HIGH_ACCURACY_TIMEOUT_MS = 15000;
const RETRY_TIMEOUT_MS = 10000;
/** A cached fix this recent and this precise is good enough for the office geofence. */
const LAST_KNOWN_MAX_AGE_MS = 60000;
const LAST_KNOWN_MAX_ACCURACY_M = 200;
/**
 * Right before sending a check only a cached fix this recent is accepted: a minute-old fix could be
 * the one taken before the worker walked out of the geofence. Without one the submit fails with
 * "Lokasi belum terbaca" instead of sending an old position.
 */
export const SUBMIT_LAST_KNOWN_MAX_AGE_MS = 10000;

export interface ReadPositionOptions {
  /** Oldest cached fix accepted when no fresh one arrives in time. */
  lastKnownMaxAgeMs?: number;
}

function withTimeout<T>(promise: Promise<T>, ms: number): Promise<T | null> {
  return new Promise((resolve) => {
    const timer = setTimeout(() => resolve(null), ms);
    promise.then(
      (value) => {
        clearTimeout(timer);
        resolve(value);
      },
      () => {
        clearTimeout(timer);
        resolve(null);
      },
    );
  });
}

function nativeCode(error: unknown): string | null {
  const code = (error as { code?: unknown } | null)?.code;
  return typeof code === 'string' ? code : null;
}

/**
 * Indonesian text for any location failure: the native Expo errors are long English sentences
 * that tell the worker nothing about what to do.
 */
export function locationErrorMessage(error: unknown): string {
  if (
    error instanceof LocationDeniedError ||
    error instanceof ApproximateLocationError ||
    error instanceof LocationServicesOffError ||
    error instanceof LocationUnavailableError
  ) {
    return error.message;
  }
  switch (nativeCode(error)) {
    case 'ERR_LOCATION_SETTINGS_UNSATISFIED':
    case 'ERR_LOCATION_SERVICES_DISABLED':
      return new LocationServicesOffError().message;
    case 'ERR_CURRENT_LOCATION_IS_UNAVAILABLE':
    case 'ERR_LOCATION_UNAVAILABLE':
    case 'ERR_CURRENT_LOCATION_UNKNOWN':
      return new LocationUnavailableError().message;
    case 'ERR_LOCATION_UNAUTHORIZED':
      return new LocationDeniedError(true).message;
    default:
      return 'Lokasi gagal dibaca. Tekan Ulangi untuk mencoba lagi.';
  }
}

/** Whether the failure is fixed by switching device location on, so the screen can offer that. */
export function isServicesOffError(error: unknown): boolean {
  return error instanceof LocationServicesOffError || nativeCode(error) === 'ERR_LOCATION_SETTINGS_UNSATISFIED';
}

/** Asks Android to switch location on (the system dialog), falling back to the location settings. */
export async function enableDeviceLocation(): Promise<void> {
  if (Platform.OS === 'android') {
    try {
      await Location.enableNetworkProviderAsync();
      return;
    } catch {
      await Linking.sendIntent('android.settings.LOCATION_SOURCE_SETTINGS').catch(() => Linking.openSettings());
      return;
    }
  }
  await Linking.openSettings();
}

/**
 * Reads the current position for a check in or check out.
 *
 * A fresh high accuracy fix is tried first, then a quicker balanced one, then a recent cached fix,
 * each bounded in time so the screen never waits forever indoors. The mocked flag always comes from
 * the position that is used, so mock detection still works.
 */
export async function readDevicePosition(options: ReadPositionOptions = {}): Promise<DevicePosition> {
  const lastKnownMaxAgeMs = options.lastKnownMaxAgeMs ?? LAST_KNOWN_MAX_AGE_MS;
  // Ask only when needed: on Android a request always opens the (invisible when already granted)
  // permission activity, which pauses and resumes the app. Asking on every read made the screen's
  // "read again when back in the app" refetch trigger itself forever on a real phone.
  const current = await Location.getForegroundPermissionsAsync();
  const mustAsk = !current.granted || (Platform.OS === 'android' && current.android?.accuracy === 'coarse');
  const permission = mustAsk ? await Location.requestForegroundPermissionsAsync() : current;
  if (!permission.granted) {
    throw new LocationDeniedError(permission.canAskAgain);
  }
  if (Platform.OS === 'android' && permission.android?.accuracy === 'coarse') {
    throw new ApproximateLocationError(permission.canAskAgain);
  }
  if (!(await Location.hasServicesEnabledAsync())) {
    throw new LocationServicesOffError();
  }

  let lastError: unknown = null;
  const attempt = (options: Location.LocationOptions, ms: number) =>
    withTimeout(
      Location.getCurrentPositionAsync(options).catch((error: unknown) => {
        lastError = error;
        throw error;
      }),
      ms,
    );

  const position =
    (await attempt({ accuracy: Location.Accuracy.High }, HIGH_ACCURACY_TIMEOUT_MS)) ??
    // Without the "improve accuracy" dialog: on Android, a refused dialog (or "device only" mode)
    // still gets a plain GPS reading this way instead of asking again.
    (await attempt({ accuracy: Location.Accuracy.High, mayShowUserSettingsDialog: false }, RETRY_TIMEOUT_MS)) ??
    (await Location.getLastKnownPositionAsync({ maxAge: lastKnownMaxAgeMs, requiredAccuracy: LAST_KNOWN_MAX_ACCURACY_M }).catch(() => null));

  if (!position) {
    if (isServicesOffError(lastError)) {
      throw new LocationServicesOffError();
    }
    throw new LocationUnavailableError();
  }

  const { latitude, longitude, accuracy } = position.coords;
  return { latitude, longitude, accuracy: accuracy ?? null, mocked: position.mocked === true };
}

/**
 * The error to show when the position read right before sending a check fails. The known location
 * errors keep their class (and Indonesian message); a native Expo error gets the Indonesian text,
 * since the submit error notice shows the message as is.
 */
export function submitLocationError(error: unknown): Error {
  if (
    error instanceof LocationDeniedError ||
    error instanceof ApproximateLocationError ||
    error instanceof LocationServicesOffError ||
    error instanceof LocationUnavailableError
  ) {
    return error;
  }
  return new Error(locationErrorMessage(error), { cause: error });
}
