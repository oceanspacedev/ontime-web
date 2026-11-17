import { onlineManager } from '@tanstack/react-query';

import { type DevicePosition, LocationUnavailableError, submitLocationError } from '../location';
import { appendFile, NetworkError, request } from './client';
import type {
  Attendance,
  AttendanceCheckResponse,
  AttendancePurpose,
  AttendanceStatus,
  CompanyPolicy,
  CoverOptions,
  LivenessChallenge,
  Paginated,
  PaginationA,
  SelfieMode,
  TodayAttendance,
  UploadFile,
} from './types';

export function today(): Promise<TodayAttendance> {
  return request<TodayAttendance>('/attendance/today');
}

/**
 * Shifts and rostered colleagues for a check-in outside the schedule ("Masuk di luar jadwal"). With the picked
 * cover shift, the server lists the colleagues of the date the check-in is booked to: a night shift covered
 * after midnight belongs to the evening it started, so its colleagues are those rostered that evening.
 */
export function coverOptions(coverShiftId: number | null = null): Promise<CoverOptions> {
  return request<CoverOptions>('/attendance/cover-options', { query: { cover_shift_id: coverShiftId } });
}

/** Message when the company has no active shift, so no off-schedule check-in can be recorded yet. */
export const NO_COVER_SHIFT_MESSAGE = 'Belum ada shift aktif di badan usaha Anda, jadi masuk di luar jadwal belum bisa dicatat. Hubungi koordinator atau PIC.';

/** What the worker picked for "Masuk di luar jadwal": the shift covered and the colleague replaced. */
export interface OffScheduleSelection {
  coverShiftId: number | null;
  replacesWorker: string | null;
}

/**
 * Whether the check-in screen offers "Masuk di luar jadwal". The cover options answer it; an older server
 * without them falls back to today's schedule (a day off, or a freelancer without a shift).
 */
export function isOffScheduleToday(
  options: CoverOptions | null | undefined,
  schedule: TodayAttendance['schedule'] | null | undefined,
  isFreelancer: boolean,
): boolean {
  if (options) {
    return options.is_off_schedule;
  }
  if (!schedule) {
    return false;
  }
  if (schedule.is_off_schedule !== undefined) {
    return schedule.is_off_schedule;
  }
  return schedule.is_day_off || (isFreelancer && !schedule.shift);
}

/**
 * Why the off-schedule check-in cannot be sent yet, or null. Every worker must name the shift they cover, so
 * the lateness, early leave, the approver and the payroll all read real hours; a picked shift or colleague
 * must still be on offer.
 */
export function offScheduleSelectionError(options: CoverOptions | null | undefined, selection: OffScheduleSelection): string | null {
  // Options not loaded (or an older server): the server still refuses a check-in without a cover shift.
  if (!options) {
    return null;
  }
  if (options.shifts.length === 0) {
    return NO_COVER_SHIFT_MESSAGE;
  }
  if (selection.coverShiftId === null) {
    return 'Pilih shift yang Anda gantikan.';
  }
  if (!options.shifts.some((shift) => shift.id === selection.coverShiftId)) {
    return 'Shift yang dipilih sudah tidak tersedia. Pilih shift lain.';
  }
  if (selection.replacesWorker !== null) {
    const colleague = options.colleagues.find((candidate) => candidate.key === selection.replacesWorker);
    if (!colleague) {
      return 'Rekan yang dipilih sudah tidak terjadwal hari ini. Pilih rekan lain.';
    }
    // Covering another shift than the colleague's own would free their shift with nobody on it.
    if (colleague.shift && colleague.shift.id !== selection.coverShiftId) {
      return 'Shift yang dipilih bukan shift rekan yang digantikan. Pilih shift rekan tersebut.';
    }
  }
  return null;
}

/**
 * Why a check-in waits for the approver: "Masuk di luar jadwal · menggantikan Budi · shift Closing" for an
 * emergency backup (the server's label, or one built from the flags on an older server), otherwise the
 * geofence / face-match notice.
 */
export function approvalReasonOf(attendance: Pick<Attendance, 'is_off_schedule' | 'off_schedule_label' | 'replaced_worker'>): string {
  if (attendance.is_off_schedule) {
    const label = attendance.off_schedule_label
      ?? ['Masuk di luar jadwal', attendance.replaced_worker ? `menggantikan ${attendance.replaced_worker.name}` : null].filter(Boolean).join(' · ');
    return `${label}. Pekerja ini masuk sebagai pengganti di hari yang tidak terjadwal. Pastikan memang diminta sebelum menyetujui.`;
  }
  return 'Presensi ini dilakukan di luar area kantor atau verifikasi wajahnya tidak cocok. Periksa foto dan lokasinya sebelum memutuskan.';
}

/**
 * The selfie mode of a policy. Older servers only send the booleans, so the mode is derived from
 * them when `selfie_mode` is missing.
 */
export function selfieModeOf(policy: CompanyPolicy | null | undefined): SelfieMode {
  if (!policy) {
    return 'none';
  }
  if (policy.selfie_mode) {
    return policy.selfie_mode;
  }
  if (policy.require_liveness) {
    return 'liveness';
  }
  if (policy.require_face_recognition) {
    return 'face';
  }
  return policy.require_photo ? 'photo' : 'none';
}

/** Starts a head-turn challenge for the next check in or check out. */
export function livenessChallenge(purpose: AttendancePurpose): Promise<LivenessChallenge> {
  return request<LivenessChallenge>('/attendance/liveness-challenge', { method: 'POST', json: { purpose } });
}

export interface CheckPayload {
  latitude?: number | null;
  longitude?: number | null;
  /** GPS accuracy in metres. */
  accuracy?: number | null;
  /** The OS reported the position as mocked. */
  isMocked?: boolean;
  photo?: UploadFile | null;
  livenessChallengeId?: string | null;
  /** One photo per challenge step, in the order of the challenge's steps. */
  livenessFrames?: UploadFile[];
  /** Check-in outside the schedule: the company shift covered. */
  coverShiftId?: number | null;
  /** Check-in outside the schedule: the colleague replaced, as "employee:12". */
  replacesWorker?: string | null;
}

export type CheckResponse = AttendanceCheckResponse;

export function buildCheckForm(payload: CheckPayload): FormData {
  const form = new FormData();
  if (payload.latitude !== undefined && payload.latitude !== null) {
    form.append('latitude', String(payload.latitude));
  }
  if (payload.longitude !== undefined && payload.longitude !== null) {
    form.append('longitude', String(payload.longitude));
  }
  if (payload.accuracy !== undefined && payload.accuracy !== null) {
    form.append('accuracy', String(Math.max(0, payload.accuracy)));
  }
  if (payload.isMocked !== undefined) {
    form.append('is_mocked', payload.isMocked ? '1' : '0');
  }
  if (payload.photo) {
    appendFile(form, 'photo', payload.photo);
  }
  if (payload.livenessChallengeId) {
    form.append('liveness_challenge_id', payload.livenessChallengeId);
  }
  for (const frame of payload.livenessFrames ?? []) {
    appendFile(form, 'liveness_frames[]', frame);
  }
  if (payload.coverShiftId !== undefined && payload.coverShiftId !== null) {
    form.append('cover_shift_id', String(payload.coverShiftId));
  }
  if (payload.replacesWorker) {
    form.append('replaces_worker', payload.replacesWorker);
  }
  return form;
}

export function checkIn(payload: CheckPayload): Promise<CheckResponse> {
  return request<CheckResponse>('/attendance/check-in', { method: 'POST', form: buildCheckForm(payload), timeoutMs: 90000 });
}

export function checkOut(payload: CheckPayload): Promise<CheckResponse> {
  return request<CheckResponse>('/attendance/check-out', { method: 'POST', form: buildCheckForm(payload), timeoutMs: 90000 });
}

/**
 * Mutation options for a check in or check out. React Query's default `online` mode pauses a
 * mutation while the device is offline and sends it later, with the GPS and selfie captured at
 * tap time, even after the screen closed. A check must happen now or fail now, so it never waits.
 */
export const checkMutationOptions = { networkMode: 'always', retry: false } as const;

/**
 * Sends a check in or check out right away. Offline it fails at once with the usual Indonesian
 * message (before reading GPS or preparing photos), so the worker can retry or close the screen.
 */
export async function submitCheck(purpose: AttendancePurpose, buildPayload: () => Promise<CheckPayload>): Promise<CheckResponse> {
  if (!onlineManager.isOnline()) {
    throw new NetworkError('offline');
  }
  const payload = await buildPayload();
  return purpose === 'check_in' ? checkIn(payload) : checkOut(payload);
}

/**
 * The longest a submit waits for the fresh position. readDevicePosition bounds each attempt itself
 * (about 25s in all indoors); this is the backstop so a native call that never answers cannot leave
 * the worker on a spinner with the close button disabled.
 */
export const SUBMIT_LOCATION_DEADLINE_MS = 35000;

function withDeadline<T>(promise: Promise<T>, ms: number): Promise<T> {
  return new Promise((resolve, reject) => {
    const timer = setTimeout(() => reject(new LocationUnavailableError()), ms);
    promise.then(
      (value) => {
        clearTimeout(timer);
        resolve(value);
      },
      (error: unknown) => {
        clearTimeout(timer);
        reject(error);
      },
    );
  });
}

/**
 * The GPS part of a check payload, read again right before sending: the fix on screen may be
 * minutes old. The read is awaited directly, not through the screen's location query, whose own
 * refetches would cancel or replace it; `onRead` then shows the sent position in the preview. A failed
 * or too slow read rejects with the Indonesian location message and never falls back to the cached fix.
 */
export async function freshCheckPosition<T extends DevicePosition>(
  read: () => Promise<T>,
  onRead?: (position: T) => void,
  deadlineMs: number = SUBMIT_LOCATION_DEADLINE_MS,
): Promise<Pick<CheckPayload, 'latitude' | 'longitude' | 'accuracy' | 'isMocked'>> {
  let fresh: T;
  try {
    fresh = await withDeadline(read(), deadlineMs);
  } catch (error) {
    throw submitLocationError(error);
  }
  onRead?.(fresh);
  return { latitude: fresh.latitude, longitude: fresh.longitude, accuracy: fresh.accuracy, isMocked: fresh.mocked };
}

export interface MasterFaceResponse {
  message: string;
  data: { master_face_photo: string; master_face_verified_at: string };
}

export function registerMasterFace(photo: UploadFile): Promise<MasterFaceResponse> {
  const form = new FormData();
  appendFile(form, 'photo', photo);
  return request<MasterFaceResponse>('/attendance/master-face', { method: 'POST', form, timeoutMs: 90000 });
}

export function deleteMasterFace(): Promise<{ message: string }> {
  return request<{ message: string }>('/attendance/master-face', { method: 'DELETE' });
}

export interface AttendanceFilter {
  page?: number;
  month?: number;
  year?: number;
  start_date?: string;
  end_date?: string;
  status?: AttendanceStatus;
}

export function list(filter: AttendanceFilter): Promise<Paginated<Attendance, PaginationA>> {
  return request<Paginated<Attendance, PaginationA>>('/attendances', { query: { ...filter } });
}

export async function show(id: number): Promise<Attendance> {
  const response = await request<{ data: Attendance }>(`/attendances/${id}`);
  return response.data;
}
