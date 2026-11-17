import { onlineManager } from '@tanstack/react-query';

/** The part of expo-network's state this app reads. */
export type ConnectionState = { isConnected?: boolean; isInternetReachable?: boolean };

export type NetworkReader = () => Promise<ConnectionState>;

/** Offline only when the device says so; an unknown value (undefined) counts as online. */
export function isOfflineState(state: ConnectionState): boolean {
  return state.isConnected === false || state.isInternetReachable === false;
}

let deviceReader: NetworkReader | null = null;

/**
 * How long a network event that said "offline" outranks a read that says "online". When Android drops a
 * network it can keep reporting it as the active, connected one for a moment, so expo-network's onLost
 * event is right and an immediate read is not (expo/expo#37972).
 */
export const RECENT_OFFLINE_EVENT_MS = 2000;

let lastOfflineEventAt = Number.NEGATIVE_INFINITY;

/** Called with each state delivered by a network event (see deviceNetwork.ts). */
export function recordNetworkEvent(state: ConnectionState, now: number = Date.now()): void {
  if (isOfflineState(state)) {
    lastOfflineEventAt = now;
  }
}

/** Whether a state read from the device, rather than delivered by an event, counts as online. */
export function isOnlineRead(state: ConnectionState, now: number = Date.now()): boolean {
  return !isOfflineState(state) && now - lastOfflineEventAt >= RECENT_OFFLINE_EVENT_MS;
}

/** Test helper: forget earlier network events. */
export function resetNetworkEvents(): void {
  lastOfflineEventAt = Number.NEGATIVE_INFINITY;
}

/**
 * Sets how the device's network state is read (expo-network in the app, see deviceNetwork.ts). Kept
 * out of this module so the API client and its tests load without the native module.
 */
export function setNetworkReader(reader: NetworkReader | null): void {
  deviceReader = reader;
}

/**
 * Reads the device's network state now and stores it in React Query's onlineManager, which the offline
 * bar and the submit guards read. Returns whether the device is online, or null when there is no reader
 * or the read failed (the stored value is then left as it was).
 */
export async function refreshOnlineState(readState: NetworkReader | null = deviceReader): Promise<boolean | null> {
  if (!readState) {
    return null;
  }
  try {
    const online = isOnlineRead(await readState());
    onlineManager.setOnline(online);
    return online;
  } catch {
    return null;
  }
}

/**
 * Whether a submit may go out. The stored state comes from network events, and Android can leave it
 * stale: on a Xiaomi 17 the app kept "offline" after the connection was back, so every form failed at
 * once without reaching the server. A stored "offline" is therefore checked against the device before a
 * submit is refused. When the device cannot be asked, the stored value stands.
 *
 * Callers test onlineManager.isOnline() first and await this only when it says offline, so an online
 * submit reaches fetch in the same tick as before.
 */
export async function confirmOnline(readState: NetworkReader | null = deviceReader): Promise<boolean> {
  if (onlineManager.isOnline()) {
    return true;
  }
  return (await refreshOnlineState(readState)) ?? false;
}
