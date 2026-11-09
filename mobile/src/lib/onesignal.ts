import Constants from 'expo-constants';
import * as SecureStore from 'expo-secure-store';
import { Platform } from 'react-native';
import type { NotificationClickEvent, NotificationWillDisplayEvent, PushSubscriptionChangedState } from 'react-native-onesignal';

/**
 * The one place that talks to the OneSignal SDK. The app identifies the signed-in user by their OnTime
 * user id (OneSignal external id), so the server sends pushes to a user without handling device tokens.
 */

const PERMISSION_ASKED_KEY = 'ontime.pushPermissionAsked';

type OneSignalModule = typeof import('react-native-onesignal');

let sdk: OneSignalModule | null = null;
let initialized = false;

/**
 * Loads the SDK only where its native module exists: importing it in Expo Go or in tests throws
 * because the native side is missing.
 */
function oneSignal(): OneSignalModule['OneSignal'] {
  // eslint-disable-next-line @typescript-eslint/no-require-imports -- loaded on demand, see above
  sdk ??= require('react-native-onesignal') as OneSignalModule;
  return sdk.OneSignal;
}

function appId(): string | null {
  const id = Constants.expoConfig?.extra?.oneSignalAppId;
  return typeof id === 'string' && id.length > 0 ? id : null;
}

/** Push needs the native module, which Expo Go and the web do not have. */
export function isPushAvailable(): boolean {
  return (Platform.OS === 'android' || Platform.OS === 'ios') && Constants.executionEnvironment !== 'storeClient' && appId() !== null;
}

/** Start the SDK once, at the app root. Does not ask for permission. */
export function initPush(): void {
  const id = appId();
  if (initialized || !id || !isPushAvailable()) {
    return;
  }
  const OneSignal = oneSignal();
  const { LogLevel } = sdk as OneSignalModule;
  OneSignal.Debug.setLogLevel(__DEV__ ? LogLevel.Warn : LogLevel.None);
  OneSignal.initialize(id);
  initialized = true;
}

/** Link this device to the signed-in user, so pushes for that user reach it. */
export function identifyPushUser(userId: number | string): void {
  if (initialized) {
    oneSignal().login(String(userId));
  }
}

/** Unlink this device on sign-out, so the next user does not get the previous user's pushes. */
export function forgetPushUser(): void {
  if (initialized) {
    oneSignal().logout();
  }
}

/** The device's push subscription id once OneSignal has registered it; local ids do not count. */
export async function pushSubscriptionId(): Promise<string | null> {
  if (!initialized) {
    return null;
  }
  const id = await oneSignal().User.pushSubscription.getIdAsync();
  return isRegisteredId(id) ? id : null;
}

export function isRegisteredId(id: string | null | undefined): id is string {
  return typeof id === 'string' && id.length > 0 && !id.startsWith('local-');
}

export function onPushSubscriptionChange(listener: (event: PushSubscriptionChangedState) => void): () => void {
  if (!initialized) {
    return () => undefined;
  }
  oneSignal().User.pushSubscription.addEventListener('change', listener);
  return () => oneSignal().User.pushSubscription.removeEventListener('change', listener);
}

export async function hasPushPermission(): Promise<boolean> {
  return initialized ? oneSignal().Notifications.getPermissionAsync() : false;
}

/** Ask for notification permission. The only place the app requests it. */
export async function requestPushPermission(): Promise<boolean> {
  if (!initialized) {
    return false;
  }
  await markPermissionAsked();
  return oneSignal().Notifications.requestPermission(true);
}

export async function wasPermissionAsked(): Promise<boolean> {
  try {
    return (await SecureStore.getItemAsync(PERMISSION_ASKED_KEY)) === '1';
  } catch {
    return false;
  }
}

async function markPermissionAsked(): Promise<void> {
  try {
    await SecureStore.setItemAsync(PERMISSION_ASKED_KEY, '1');
  } catch {
    // Without secure storage the explanation may simply show again later.
  }
}

/** The `data` the server attached to a tapped push. */
export function onPushClick(listener: (data: unknown) => void): () => void {
  if (!initialized) {
    return () => undefined;
  }
  const handler = (event: NotificationClickEvent) => listener(event.notification.additionalData ?? null);
  oneSignal().Notifications.addEventListener('click', handler);
  return () => oneSignal().Notifications.removeEventListener('click', handler);
}

/** Runs for a push that arrives while the app is open; the push is still shown. */
export function onPushInForeground(listener: (data: unknown) => void): () => void {
  if (!initialized) {
    return () => undefined;
  }
  const handler = (event: NotificationWillDisplayEvent) => listener(event.getNotification().additionalData ?? null);
  oneSignal().Notifications.addEventListener('foregroundWillDisplay', handler);
  return () => oneSignal().Notifications.removeEventListener('foregroundWillDisplay', handler);
}
