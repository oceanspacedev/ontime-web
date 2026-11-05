import { appendFile, request } from './client';
import type { Attendance, AttendanceStatus, Paginated, PaginationA, TodayAttendance, UploadFile } from './types';

export function today(): Promise<TodayAttendance> {
  return request<TodayAttendance>('/attendance/today');
}

export interface CheckPayload {
  latitude?: number | null;
  longitude?: number | null;
  photo?: UploadFile | null;
}

export interface CheckResponse {
  message: string;
  attendance: Attendance;
}

function buildCheckForm(payload: CheckPayload): FormData {
  const form = new FormData();
  if (payload.latitude !== undefined && payload.latitude !== null) {
    form.append('latitude', String(payload.latitude));
  }
  if (payload.longitude !== undefined && payload.longitude !== null) {
    form.append('longitude', String(payload.longitude));
  }
  if (payload.photo) {
    appendFile(form, 'photo', payload.photo);
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
