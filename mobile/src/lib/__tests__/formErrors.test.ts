/// <reference types="jest" />
import { ApiError, NetworkError } from '../api/client';
import { isForbidden, isRefusal, unshownErrorMessage } from '../errors';

const NO_FLOW = 'Alur approval untuk jenis pengajuan ini belum dikonfigurasi oleh Perusahaan.';
const LEAVE_FIELDS = ['leave_type', 'start_date', 'end_date', 'reason', 'attachment'];

describe('unshownErrorMessage', () => {
  it('is null before anything failed', () => {
    expect(unshownErrorMessage(null, LEAVE_FIELDS)).toBeNull();
  });

  it('shows a 422 keyed to a field the form does not have (missing approval flow)', () => {
    const error = new ApiError(422, NO_FLOW, { approval_flow: [NO_FLOW] });

    expect(unshownErrorMessage(error, LEAVE_FIELDS)).toBe(NO_FLOW);
  });

  it('shows the company check of a worker without a company', () => {
    const error = new ApiError(422, 'Pekerja belum terhubung ke perusahaan.', { company: ['Pekerja belum terhubung ke perusahaan.'] });

    expect(unshownErrorMessage(error, LEAVE_FIELDS)).toBe('Pekerja belum terhubung ke perusahaan.');
  });

  it('stays quiet when every message sits next to a field the form shows', () => {
    const error = new ApiError(422, 'Alasan wajib diisi.', { reason: ['Alasan wajib diisi.'], end_date: ['Tanggal selesai tidak valid.'] });

    expect(unshownErrorMessage(error, LEAVE_FIELDS)).toBeNull();
  });

  it('picks the unshown message when shown and unshown errors come together', () => {
    const error = new ApiError(422, 'Alasan wajib diisi.', { reason: ['Alasan wajib diisi.'], approval_flow: [NO_FLOW] });

    expect(unshownErrorMessage(error, LEAVE_FIELDS)).toBe(NO_FLOW);
  });

  it('shows the last GPS location message when the form has no field for is_active', () => {
    const message = 'Wajib Lokasi GPS membutuhkan titik lokasi badan usaha atau minimal satu lokasi absensi aktif.';
    const error = new ApiError(422, message, { is_active: [message] });

    expect(unshownErrorMessage(error, ['latitude', 'longitude', 'name'])).toBe(message);
    expect(unshownErrorMessage(error, ['latitude', 'longitude', 'name', 'is_active'])).toBeNull();
  });

  it('shows a server message for a policy field that is hidden right now', () => {
    const error = new ApiError(422, 'Ambang kemiripan wajah antara 1 dan 100.', { face_match_threshold: ['Ambang kemiripan wajah antara 1 dan 100.'] });

    expect(unshownErrorMessage(error, ['work_start_time', 'selfie_mode'])).toBe('Ambang kemiripan wajah antara 1 dan 100.');
  });

  it('shows the message of an error without field errors', () => {
    expect(unshownErrorMessage(new ApiError(500, 'Terjadi kesalahan di server. Coba lagi beberapa saat.'), LEAVE_FIELDS)).toBe(
      'Terjadi kesalahan di server. Coba lagi beberapa saat.',
    );
    expect(unshownErrorMessage(new NetworkError('offline'), LEAVE_FIELDS)).toEqual(expect.any(String));
  });
});

describe('isRefusal', () => {
  it('treats a 403 for an inactive worker as final, like 409 and 422', () => {
    expect(isRefusal(new ApiError(403, 'Status kerja Anda tidak aktif. Hubungi koordinator atau HR.'))).toBe(true);
    expect(isRefusal(new ApiError(409, 'Anda sudah absen masuk hari ini.'))).toBe(true);
    expect(isRefusal(new ApiError(422, 'Di luar area kantor.'))).toBe(true);
  });

  it('leaves server and network failures retryable', () => {
    expect(isRefusal(new ApiError(500, 'Terjadi kesalahan di server.'))).toBe(false);
    expect(isRefusal(new ApiError(429, 'Terlalu banyak percobaan.'))).toBe(false);
    expect(isRefusal(new NetworkError('offline'))).toBe(false);
    expect(isRefusal(new Error('Foto langkah belum diambil.'))).toBe(false);
  });
});

describe('isForbidden', () => {
  it('is only true for a 403', () => {
    expect(isForbidden(new ApiError(403, 'Status kerja Anda tidak aktif.'))).toBe(true);
    expect(isForbidden(new ApiError(422, 'Mode berubah.'))).toBe(false);
    expect(isForbidden(null)).toBe(false);
  });
});
