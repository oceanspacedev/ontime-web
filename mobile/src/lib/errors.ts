import { ApiError, errorMessage } from './api/client';

/**
 * The message a form shows in its general notice after a failed submit, or null when every message
 * already sits next to a field the form shows.
 *
 * A 422 can be keyed to something the form has no field for (the server's `approval_flow` or
 * `company` check, or a field that is hidden right now). Without this the submit stops with nothing
 * on screen, so the first such message is shown instead.
 */
export function unshownErrorMessage(error: unknown, shownFields: readonly string[]): string | null {
  if (!error) {
    return null;
  }
  if (!(error instanceof ApiError) || !error.errors) {
    return errorMessage(error);
  }
  for (const [key, messages] of Object.entries(error.errors)) {
    if (!shownFields.includes(key) && messages[0]) {
      return messages[0];
    }
  }
  return null;
}

/**
 * Whether a failed submit can never succeed by sending the same request again: the server refused it
 * on a rule (422, 409) or on who the user is (403, e.g. an inactive worker). Retrying only repeats it.
 */
export function isRefusal(error: unknown): error is ApiError {
  return error instanceof ApiError && (error.status === 403 || error.status === 409 || error.status === 422);
}

/** A 403: the server refuses this user outright (e.g. an inactive worker), so asking again cannot help. */
export function isForbidden(error: unknown): error is ApiError {
  return error instanceof ApiError && error.status === 403;
}
