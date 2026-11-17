import { onlineManager } from '@tanstack/react-query';
import { File } from 'expo-file-system';

import type { UploadFile } from './types';

export class ApiError extends Error {
  status: number;
  errors: Record<string, string[]> | null;

  constructor(status: number, message: string, errors: Record<string, string[]> | null = null) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
    this.errors = errors;
  }

  /** First validation message, or the general message. */
  get detail(): string {
    if (this.errors) {
      const first = Object.values(this.errors).flat()[0];
      if (first) {
        return first;
      }
    }
    return this.message;
  }
}

/** `offline`: the request never reached the server. `timeout`: no full answer arrived in time. */
export type NetworkErrorKind = 'offline' | 'timeout';

const NETWORK_MESSAGES: Record<NetworkErrorKind, string> = {
  offline: 'Tidak ada koneksi internet. Periksa koneksi Anda lalu coba lagi.',
  timeout: 'Koneksi internet lambat atau terputus. Periksa koneksi Anda lalu coba lagi.',
};

/**
 * The server could not be reached: no connection, a DNS or TLS failure, or a request that timed
 * out. The message is ready to show to the user; the technical reason stays in `cause`.
 */
export class NetworkError extends Error {
  kind: NetworkErrorKind;

  constructor(kind: NetworkErrorKind, cause?: unknown) {
    super(NETWORK_MESSAGES[kind]);
    this.name = 'NetworkError';
    this.kind = kind;
    this.cause = cause;
  }
}

export function isNetworkError(error: unknown): error is NetworkError {
  return error instanceof NetworkError;
}

export interface ApiConfig {
  /** Null when the build has no usable API host: every request then fails before leaving the device. */
  getBaseUrl: () => string | null;
  getToken: () => string | null;
  onUnauthenticated: () => void;
}

let config: ApiConfig = {
  getBaseUrl: () => null,
  getToken: () => null,
  onUnauthenticated: () => {},
};

export function configureApi(next: ApiConfig): void {
  config = next;
}

export type QueryParams = { [key: string]: string | number | boolean | null | undefined };

interface RequestOptions {
  method?: 'GET' | 'POST' | 'PUT' | 'DELETE';
  query?: QueryParams;
  json?: unknown;
  form?: FormData;
  /** Skip the automatic sign-out on 401, used by the login call itself. */
  skipAuthHandling?: boolean;
  timeoutMs?: number;
  /** Send this session token instead of the current one, e.g. to revoke a session already cleared locally. */
  token?: string | null;
  /** Send to this server instead of the current one, so a revoke reaches the server that issued the token. */
  baseUrl?: string;
}

export function buildUrl(baseUrl: string, path: string, query?: QueryParams): string {
  const base = baseUrl.replace(/\/+$/, '');
  const url = `${base}/${path.replace(/^\/+/, '')}`;
  if (!query) {
    return url;
  }
  const parts = Object.entries(query)
    .filter(([, value]) => value !== undefined && value !== null && value !== '')
    .map(([key, value]) => `${encodeURIComponent(key)}=${encodeURIComponent(String(value))}`);
  return parts.length ? `${url}?${parts.join('&')}` : url;
}

/** Text to show the user for any error. Network failures already carry a friendly message. */
export function errorMessage(error: unknown): string {
  if (error instanceof ApiError) {
    return error.detail;
  }
  if (error instanceof Error) {
    return error.message;
  }
  return 'Terjadi kesalahan yang tidak diketahui.';
}

/** Indonesian text per status, used when the server gives no message of its own. */
const STATUS_MESSAGES: Record<number, string> = {
  413: 'Ukuran foto atau lampiran terlalu besar untuk dikirim. Ambil ulang lalu coba lagi.',
  429: 'Terlalu banyak percobaan. Tunggu sebentar lalu coba lagi.',
  500: 'Terjadi kesalahan di server. Coba lagi beberapa saat.',
  502: 'Server sedang tidak tersedia. Coba lagi beberapa saat.',
  503: 'Server sedang tidak tersedia. Coba lagi beberapa saat.',
  504: 'Server tidak merespons. Coba lagi beberapa saat.',
};

/** Laravel's built-in English messages, which say nothing useful to a worker. */
const FRAMEWORK_DEFAULTS = /^(Server Error|The POST data is too large\.|Too Many Attempts\.|Service Unavailable)$/;

/**
 * The message to show for a failed request: the server's own message, unless it is missing, not
 * JSON (an HTML page from a proxy, e.g. a 413 from nginx) or one of Laravel's English defaults.
 */
export function failureMessage(status: number, serverMessage: string | undefined, unreadableBody: boolean): string {
  const message = typeof serverMessage === 'string' ? serverMessage.trim() : '';
  if (message && !FRAMEWORK_DEFAULTS.test(message)) {
    return message;
  }
  const known = STATUS_MESSAGES[status] ?? (status >= 500 ? STATUS_MESSAGES[500] : undefined);
  if (known) {
    return known;
  }
  return unreadableBody ? `Server membalas dengan data yang tidak dikenali (kode ${status}). Coba lagi nanti.` : `Permintaan gagal (${status}).`;
}

/**
 * Sends the request and reads the whole answer. The one place that recognises a network failure:
 * whatever `fetch` throws, and an answer that does not arrive in full within `timeoutMs`, becomes
 * a NetworkError. The wording of the underlying error differs per platform and fetch
 * implementation (Expo's fetch reports the raw native reason, such as "Unable to resolve host"),
 * so it is never matched and never shown.
 */
async function exchange(url: string, init: RequestInit, timeoutMs: number): Promise<{ response: Response; text: string }> {
  const controller = new AbortController();
  let timer: ReturnType<typeof setTimeout> | undefined;

  // Timed here as well as through the abort signal: a connection that drops while the body is
  // being read can leave the native read pending forever, and the abort alone would not end it.
  const timeout = new Promise<never>((_, reject) => {
    timer = setTimeout(() => {
      reject(new NetworkError('timeout'));
      controller.abort();
    }, timeoutMs);
  });

  const answer = (async () => {
    const response = await fetch(url, { ...init, signal: controller.signal });
    return { response, text: await response.text() };
  })();
  // After a timeout the abandoned request still settles; that late failure is expected.
  answer.catch(() => {});

  try {
    return await Promise.race([answer, timeout]);
  } catch (cause) {
    throw isNetworkError(cause) ? cause : new NetworkError('offline', cause);
  } finally {
    clearTimeout(timer);
  }
}

const UNREADABLE_ANSWER_MESSAGE =
  'Server membalas dengan data yang tidak dikenali. Jika memakai Wi-Fi umum, buka browser untuk login Wi-Fi atau ganti ke data seluler, lalu coba lagi.';

const MISSING_HOST_MESSAGE = 'Alamat server aplikasi belum diatur untuk build ini. Hubungi admin untuk build yang benar.';

export async function request<T>(path: string, options: RequestOptions = {}): Promise<T> {
  const { method = 'GET', query, json, form, skipAuthHandling = false, timeoutMs = 30000 } = options;

  const headers: Record<string, string> = { Accept: 'application/json' };
  const token = options.token !== undefined ? options.token : config.getToken();
  if (token) {
    headers.Authorization = `Bearer ${token}`;
  }

  let body: BodyInit | undefined;
  if (form) {
    body = form;
  } else if (json !== undefined) {
    headers['Content-Type'] = 'application/json';
    body = JSON.stringify(json);
  }

  const baseUrl = options.baseUrl ?? config.getBaseUrl();
  if (!baseUrl) {
    throw new Error(MISSING_HOST_MESSAGE);
  }

  // A submit while the device is known to be offline fails at once instead of waiting for a fetch
  // that cannot succeed (React Query sends mutations even offline: see queryClient defaults).
  if (method !== 'GET' && !onlineManager.isOnline()) {
    throw new NetworkError('offline');
  }

  const { response, text } = await exchange(buildUrl(baseUrl, path, query), { method, headers, body }, timeoutMs);
  let payload: unknown = null;
  let unreadableBody = false;
  if (text) {
    try {
      payload = JSON.parse(text);
    } catch {
      payload = null;
      unreadableBody = true;
    }
  }

  if (!response.ok) {
    const data = (payload ?? {}) as { message?: string; errors?: Record<string, string[]> };
    // Only the token still in use ends the session: a late 401 for an earlier account's request
    // (or a revoke sent with a captured token) must not sign out whoever is signed in now.
    if (response.status === 401 && !skipAuthHandling && token === config.getToken()) {
      config.onUnauthenticated();
    }
    throw new ApiError(response.status, failureMessage(response.status, data.message, unreadableBody), data.errors ?? null);
  }

  // A 2xx answer that is not JSON is not ours: typically a Wi-Fi login page (captive portal) at a mall
  // or outlet. Handing null to the screen would render nothing or crash with an English TypeError.
  if (unreadableBody) {
    throw new ApiError(response.status, UNREADABLE_ANSWER_MESSAGE);
  }

  return payload as T;
}

/** A multipart file part that expo/fetch can send: it reads the bytes itself when the body is built. */
export interface FilePart {
  name: string;
  type: string;
  bytes: () => Promise<Uint8Array>;
}

/**
 * Adds a local file to a multipart form. The app's global fetch is expo/fetch (installed by Expo on
 * every native build), which rejects React Native's `{uri, name, type}` descriptor with
 * "Unsupported FormDataPart implementation" before any request is sent; a part with `bytes()` is
 * what it accepts. The file is read only when the request body is built.
 */
export function filePart(file: UploadFile): FilePart {
  return { name: file.name, type: file.type, bytes: () => new File(file.uri).bytes() };
}

export function appendFile(form: FormData, field: string, file: UploadFile): void {
  form.append(field, filePart(file) as unknown as Blob);
}
