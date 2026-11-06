import * as SecureStore from 'expo-secure-store';

import type { ApprovalType } from './api/types';

/** Where an older app version kept its Expo push token; read once more on sign-out, then dropped. */
const LEGACY_PUSH_TOKEN_KEY = 'ontime.pushToken';

/** What the server puts in `data` of every push (OneSignal "additional data"). */
export interface PushData {
  kind?: 'approval_requested' | 'approval_resolved' | 'attendance_reminder' | 'team_missing' | (string & {});
  request_type?: ApprovalType | (string & {});
  request_id?: number | string;
  step_order?: number;
  status?: 'approved' | 'rejected' | (string & {});
  /** For attendance reminders: which check the reminder is about. */
  action?: 'check_in' | 'check_out' | (string & {});
}

export type PushTarget =
  | { pathname: '/(app)/approvals' }
  | { pathname: '/(app)/team' }
  | { pathname: '/(app)' }
  | { pathname: '/attendance/check'; params: { mode: 'in' | 'out' } }
  | { pathname: '/approval/[type]/[id]'; params: { type: ApprovalType; id: string } }
  | { pathname: '/request/[type]/[id]'; params: { type: Exclude<ApprovalType, 'geofence'>; id: string } }
  | { pathname: '/attendance/[id]'; params: { id: string } };

const APPROVAL_TYPES: ApprovalType[] = ['leave', 'overtime', 'correction', 'geofence', 'resignation'];

/** Screen to open when a push is tapped; null when the payload is not one of ours. */
export function pushTarget(data: unknown, canApprove: boolean, canManageTeam = false): PushTarget | null {
  if (!data || typeof data !== 'object') {
    return null;
  }
  const payload = data as PushData;
  const type = APPROVAL_TYPES.find((value) => value === payload.request_type);
  const id = payload.request_id !== undefined && payload.request_id !== null ? String(payload.request_id) : null;

  if (payload.kind === 'attendance_reminder') {
    // Reminders open the check screen straight away; for "forgot to check out" that is still right.
    if (payload.action === 'check_in') {
      return { pathname: '/attendance/check', params: { mode: 'in' } };
    }
    if (payload.action === 'check_out') {
      return { pathname: '/attendance/check', params: { mode: 'out' } };
    }
    return { pathname: '/(app)' };
  }

  if (payload.kind === 'team_missing') {
    return canManageTeam ? { pathname: '/(app)/team' } : null;
  }

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

/**
 * Hand back and forget the Expo push token an older app version registered, so sign-out can ask the
 * server to drop it. Push now goes through OneSignal (see onesignal.ts), which registers nothing here.
 */
export async function takeStoredPushToken(): Promise<string | null> {
  try {
    const token = await SecureStore.getItemAsync(LEGACY_PUSH_TOKEN_KEY);
    if (token) {
      await SecureStore.deleteItemAsync(LEGACY_PUSH_TOKEN_KEY);
    }
    return token;
  } catch {
    return null;
  }
}
