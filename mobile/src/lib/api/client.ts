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

export interface ApiConfig {
  getBaseUrl: () => string;
  getToken: () => string | null;
  onUnauthenticated: () => void;
}

let config: ApiConfig = {
  getBaseUrl: () => '',
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

export function errorMessage(error: unknown): string {
  if (error instanceof ApiError) {
    return error.detail;
  }
  if (error instanceof Error) {
    if (error.name === 'AbortError') {
      return 'Server tidak merespons. Periksa koneksi internet Anda.';
    }
    if (/network request failed/i.test(error.message)) {
      return 'Tidak bisa terhubung ke server. Periksa koneksi internet Anda.';
    }
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

  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), timeoutMs);

  let response: Response;
  try {
    response = await fetch(buildUrl(options.baseUrl ?? config.getBaseUrl(), path, query), {
      method,
      headers,
      body,
      signal: controller.signal,
    });
  } finally {
    clearTimeout(timer);
  }

  const text = await response.text();
  let payload: unknown = null;
  if (text) {
    try {
      payload = JSON.parse(text);
    } catch {
      payload = null;
    }
  }

  if (!response.ok) {
    const data = (payload ?? {}) as { message?: string; errors?: Record<string, string[]> };
    if (response.status === 401 && !skipAuthHandling) {
      config.onUnauthenticated();
    }
    throw new ApiError(response.status, failureMessage(response.status, data.message, payload === null && Boolean(text)), data.errors ?? null);
  }

  return payload as T;
}

export function appendFile(form: FormData, field: string, file: UploadFile): void {
  // React Native's FormData accepts a {uri, name, type} descriptor for files.
  form.append(field, file as unknown as Blob);
}
