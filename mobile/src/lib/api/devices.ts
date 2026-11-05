import { request } from './client';

export type DevicePlatform = 'ios' | 'android';

export interface RegisteredDevice {
  token: string;
  platform: DevicePlatform;
  last_seen_at: string | null;
}

export function register(token: string, platform: DevicePlatform): Promise<{ message: string; device: RegisteredDevice }> {
  return request('/devices', { method: 'POST', json: { token, platform } });
}

/** Called before logout, so a 401 here must not trigger another sign-out. */
export function unregister(token: string): Promise<{ message: string }> {
  return request('/devices', { method: 'DELETE', json: { token }, skipAuthHandling: true, timeoutMs: 8000 });
}
