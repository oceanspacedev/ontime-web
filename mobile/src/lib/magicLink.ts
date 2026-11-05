import * as SecureStore from 'expo-secure-store';

const PENDING_KEY = 'ontime.magicLink';

/** The email link request this device made; only this device can redeem the link's token. */
export interface PendingMagicLink {
  challengeId: string;
  email: string;
  /** Epoch milliseconds after which the server no longer accepts the link. */
  expiresAt: number;
}

let memoryFallback: PendingMagicLink | null = null;

export async function savePendingMagicLink(pending: PendingMagicLink): Promise<void> {
  try {
    await SecureStore.setItemAsync(PENDING_KEY, JSON.stringify(pending));
  } catch {
    // Without secure storage the link still works while the app stays open; see readPendingMagicLink.
    memoryFallback = pending;
  }
}

export async function readPendingMagicLink(): Promise<PendingMagicLink | null> {
  let pending = memoryFallback;
  try {
    const raw = await SecureStore.getItemAsync(PENDING_KEY);
    pending = raw ? (JSON.parse(raw) as PendingMagicLink) : pending;
  } catch {
    // Fall back to the in-memory copy.
  }
  return pending && pending.expiresAt > Date.now() ? pending : null;
}

export async function clearPendingMagicLink(): Promise<void> {
  memoryFallback = null;
  try {
    await SecureStore.deleteItemAsync(PENDING_KEY);
  } catch {
    // Nothing stored.
  }
}
