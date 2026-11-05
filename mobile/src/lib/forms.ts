import { ApiError } from './api/client';

export type FieldErrors = Record<string, string>;

/** Maps a 422 response to per-field messages so the form can show them in place. */
export function fieldErrorsFrom(error: unknown): FieldErrors {
  if (!(error instanceof ApiError) || !error.errors) {
    return {};
  }
  const result: FieldErrors = {};
  for (const [key, messages] of Object.entries(error.errors)) {
    if (messages[0]) {
      result[key] = messages[0];
    }
  }
  return result;
}

export function requiredMessage(label: string): string {
  return `${label} wajib diisi.`;
}
