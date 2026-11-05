import Constants from 'expo-constants';
import * as Notifications from 'expo-notifications';
import { AndroidImportance, IosAuthorizationStatus } from 'expo-notifications';
import * as SecureStore from 'expo-secure-store';
import { Platform } from 'react-native';

import { devicesApi } from './api';
import type { ApprovalType } from './api/types';

const PUSH_TOKEN_KEY = 'ontime.pushToken';
const ANDROID_CHANNEL_ID = 'default';

/** Notifications that arrive while the app is open are still shown as a banner. */
Notifications.setNotificationHandler({
  handleNotification: async () => ({
    shouldShowBanner: true,
    shouldShowList: true,
    shouldPlaySound: true,
    shouldSetBadge: false,
  }),
});

/** What the server puts in `data` of every push (see API.md 3.12). */
export interface PushData {
  kind?: 'approval_requested' | 'approval_resolved' | (string & {});
  request_type?: ApprovalType | (string & {});
  request_id?: number | string;
  step_order?: number;
  status?: 'approved' | 'rejected' | (string & {});
}

export type PushTarget =
  | { pathname: '/(app)/approvals' }
  | { pathname: '/approval/[type]/[id]'; params: { type: ApprovalType; id: string } }
  | { pathname: '/request/[type]/[id]'; params: { type: Exclude<ApprovalType, 'geofence'>; id: string } }
  | { pathname: '/attendance/[id]'; params: { id: string } };

const APPROVAL_TYPES: ApprovalType[] = ['leave', 'overtime', 'correction', 'geofence', 'resignation'];

/** Screen to open when a push is tapped; null when the payload is not one of ours. */
export function pushTarget(data: unknown, canApprove: boolean): PushTarget | null {
  if (!data || typeof data !== 'object') {
    return null;
  }
  const payload = data as PushData;
  const type = APPROVAL_TYPES.find((value) => value === payload.request_type);
  const id = payload.request_id !== undefined && payload.request_id !== null ? String(payload.request_id) : null;

  if (payload.kind === 'approval_requested') {
    if (type && id && canApprove) {
      return { pathname: '/approval/[type]/[id]', params: { type, id } };
    }
    return canApprove ? { pathname: '/(app)/approvals' } : null;
  }

  if (payload.kind === 'approval_resolved' && type && id) {
    if (type === 'geofence') {
      return { pathname: '/attendance/[id]', params: { id } };
    }
    return { pathname: '/request/[type]/[id]', params: { type, id } };
  }

  return null;
}

async function readStoredToken(): Promise<string | null> {
  try {
    return await SecureStore.getItemAsync(PUSH_TOKEN_KEY);
  } catch {
    return null;
  }
}

async function writeStoredToken(token: string | null): Promise<void> {
  try {
    if (token === null) {
      await SecureStore.deleteItemAsync(PUSH_TOKEN_KEY);
    } else {
      await SecureStore.setItemAsync(PUSH_TOKEN_KEY, token);
    }
  } catch {
    // Without secure storage the token is simply registered again on the next launch.
  }
}

function expoProjectId(): string | undefined {
  return Constants.expoConfig?.extra?.eas?.projectId ?? Constants.easConfig?.projectId ?? undefined;
}

async function ensurePermission(): Promise<boolean> {
  if (Platform.OS === 'android') {
    // Android 13+ only shows the permission prompt once a channel exists.
    await Notifications.setNotificationChannelAsync(ANDROID_CHANNEL_ID, {
      name: 'Pengajuan dan persetujuan',
      importance: AndroidImportance.MAX,
      vibrationPattern: [0, 250, 250, 250],
    });
  }

  const current = await Notifications.getPermissionsAsync();
  if (current.granted || current.ios?.status === IosAuthorizationStatus.PROVISIONAL) {
    return true;
  }
  if (!current.canAskAgain) {
    return false;
  }
  const requested = await Notifications.requestPermissionsAsync();
  return requested.granted || requested.ios?.status === IosAuthorizationStatus.PROVISIONAL;
}

/**
 * Ask for permission, fetch the Expo push token and register it with the API.
 * Returns the token, or null when push is unavailable (permission denied, Expo Go, no EAS project id).
 */
export async function registerPushToken(): Promise<string | null> {
  if (Platform.OS !== 'ios' && Platform.OS !== 'android') {
    return null;
  }

  const projectId = expoProjectId();
  if (!projectId) {
    console.warn('Push dinonaktifkan: isi extra.eas.projectId di app.json (lihat API.md 3.12).');
    return null;
  }

  try {
    if (!(await ensurePermission())) {
      return null;
    }
    const token = (await Notifications.getExpoPushTokenAsync({ projectId })).data;
    await devicesApi.register(token, Platform.OS);
    await writeStoredToken(token);
    return token;
  } catch (error) {
    // Typical causes: Expo Go (no remote push since SDK 53), offline, missing FCM/APNs credentials.
    console.warn('Gagal mendaftarkan push token', error);
    return null;
  }
}

/**
 * Forget this install's registered push token and return it, so sign-out can ask the server to
 * drop it along with the session. The next sign-in registers it again.
 */
export async function takeStoredPushToken(): Promise<string | null> {
  const token = await readStoredToken();
  if (token) {
    await writeStoredToken(null);
  }
  return token;
}
