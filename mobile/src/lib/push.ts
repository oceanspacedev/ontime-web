import * as SecureStore from 'expo-secure-store';

import type { ApprovalType } from './api/types';

/** Where an older app version kept its Expo push token; read once more on sign-out, then dropped. */
const LEGACY_PUSH_TOKEN_KEY = 'ontime.pushToken';

/** What the server puts in `data` of every push (OneSignal "additional data"). */
export interface PushData {
  kind?: 'approval_requested' | 'approval_resolved' | 'attendance_reminder' | 'team_missing' | 'shift_swap' | (string & {});
  request_type?: ApprovalType | (string & {});
  request_id?: number | string;
  step_order?: number;
  status?: 'approved' | 'rejected' | (string & {});
  /** For attendance reminders: which check the reminder is about, or "correction" for an earlier shift never checked out. */
  action?: 'check_in' | 'check_out' | 'correction' | (string & {});
  /** For a "correction" reminder: the date (YYYY-MM-DD) of the shift to correct. */
  date?: string;
}

export type PushTarget =
  | { pathname: '/(app)/approvals' }
  | { pathname: '/(app)/team' }
  | { pathname: '/(app)' }
  | { pathname: '/attendance/check'; params: { mode: 'in' | 'out' } }
  | { pathname: '/request/correction/new'; params: { date: string } }
  | { pathname: '/request/shift-swap' }
  | { pathname: '/approval/shift-swap/[id]'; params: { id: string } }
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
    // An earlier shift never checked out: the correction form, on that shift's date.
    if (payload.action === 'correction' && typeof payload.date === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(payload.date)) {
      return { pathname: '/request/correction/new', params: { date: payload.date } };
    }
    return { pathname: '/(app)' };
  }

  if (payload.kind === 'team_missing') {
    return canManageTeam ? { pathname: '/(app)/team' } : null;
  }

  // Shift swap updates (requested, accepted, declined, approved, failed, ...) go to the worker's swap list.
  if (payload.kind === 'shift_swap') {
    return { pathname: '/request/shift-swap' };
  }

  if (payload.kind === 'approval_requested') {
    if (payload.request_type === 'shift_swap' && id && canApprove) {
      return { pathname: '/approval/shift-swap/[id]', params: { id } };
    }
    if (type && id && canApprove) {
      return { pathname: '/approval/[type]/[id]', params: { type, id } };
    }
    return canApprove ? { pathname: '/(app)/approvals' } : null;
  }

  // A decided Tukar Shift reaches its requester only as approval_resolved: open the swap list.
  if (payload.kind === 'approval_resolved' && payload.request_type === 'shift_swap') {
    return { pathname: '/request/shift-swap' };
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
