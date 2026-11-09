import { request } from './client';
import type { LoginResponse, MagicLinkRequestResponse, OtpChannel, OtpRequestResponse, User } from './types';

export function login(email: string, password: string): Promise<LoginResponse> {
  return request<LoginResponse>('/auth/login', {
    method: 'POST',
    json: { email, password },
    skipAuthHandling: true,
  });
}

/** Send a one-time code over WhatsApp (destination = phone) or email (destination = email). */
export function requestOtp(channel: OtpChannel, destination: string): Promise<OtpRequestResponse> {
  return request<OtpRequestResponse>('/auth/otp/request', {
    method: 'POST',
    json: channel === 'whatsapp' ? { channel, phone: destination } : { channel, email: destination },
    skipAuthHandling: true,
  });
}

/** Exchange a challenge id and its code for a token; same payload as login. */
export function verifyOtp(challengeId: string, code: string): Promise<LoginResponse> {
  return request<LoginResponse>('/auth/otp/verify', {
    method: 'POST',
    json: { challenge_id: challengeId, code },
    skipAuthHandling: true,
  });
}

/** Email a link that opens the app; keep the challenge id to redeem the link's token later. */
export function requestMagicLink(email: string): Promise<MagicLinkRequestResponse> {
  return request<MagicLinkRequestResponse>('/auth/magic-link/request', {
    method: 'POST',
    json: { email },
    skipAuthHandling: true,
  });
}

/** Exchange the challenge id and the token from the emailed link for a session; same payload as login. */
export function verifyMagicLink(challengeId: string, token: string): Promise<LoginResponse> {
  return request<LoginResponse>('/auth/magic-link/verify', {
    method: 'POST',
    json: { challenge_id: challengeId, token },
    skipAuthHandling: true,
  });
}

export async function me(): Promise<User> {
  const response = await request<{ user: User }>('/auth/me');
  return response.user;
}

/**
 * Revoke a session on the server that issued it; with pushToken this install also stops getting
 * that account's pushes. Takes the session explicitly because the app clears it locally first.
 */
export function logout(session: { token: string; baseUrl: string }, pushToken: string | null): Promise<{ message: string }> {
  return request<{ message: string }>('/auth/logout', {
    method: 'POST',
    json: pushToken ? { push_token: pushToken } : {},
    token: session.token,
    baseUrl: session.baseUrl,
    skipAuthHandling: true,
    timeoutMs: 10000,
  });
}
