import { appendFile, request } from './client';
import type {
  Attendance,
  AttendanceCheckResponse,
  AttendancePurpose,
  AttendanceStatus,
  CompanyPolicy,
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
}

export type CheckResponse = AttendanceCheckResponse;

function buildCheckForm(payload: CheckPayload): FormData {
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
  return form;
}

export function checkIn(payload: CheckPayload): Promise<CheckResponse> {
  return request<CheckResponse>('/attendance/check-in', { method: 'POST', form: buildCheckForm(payload), timeoutMs: 90000 });
}

export function checkOut(payload: CheckPayload): Promise<CheckResponse> {
  return request<CheckResponse>('/attendance/check-out', { method: 'POST', form: buildCheckForm(payload), timeoutMs: 90000 });
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
