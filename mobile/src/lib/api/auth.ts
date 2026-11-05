import { request } from './client';
import type { LoginResponse, OtpChannel, OtpRequestResponse, User } from './types';

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

export async function me(): Promise<User> {
  const response = await request<{ user: User }>('/auth/me');
  return response.user;
}

export function logout(): Promise<{ message: string }> {
  return request<{ message: string }>('/auth/logout', { method: 'POST' });
}
