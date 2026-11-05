import { appendFile, request } from './client';
import type {
  AttendanceCorrection,
  LeaveListResponse,
  LeaveRequest,
  OvertimeRequest,
  Paginated,
  Resignation,
  UploadFile,
} from './types';

export interface LeavePayload {
  leave_type: string;
  start_date: string;
  end_date: string;
  reason: string;
  attachment?: UploadFile | null;
}

export interface OvertimePayload {
  date: string;
  start_time: string;
  end_time: string;
  reason: string;
}

export interface CorrectionPayload {
  date: string;
  corrected_check_in?: string | null;
  corrected_check_out?: string | null;
  reason: string;
  attachment?: UploadFile | null;
}

export interface ResignationPayload {
  resignation_date: string;
  last_working_day: string;
  reason: string;
  handover_notes?: string | null;
}

function toForm(fields: Record<string, string | null | undefined>, attachment?: UploadFile | null): FormData {
  const form = new FormData();
  for (const [key, value] of Object.entries(fields)) {
    if (value !== undefined && value !== null && value !== '') {
      form.append(key, value);
    }
  }
  if (attachment) {
    appendFile(form, 'attachment', attachment);
  }
  return form;
}

export const leave = {
  list: (page = 1) => request<LeaveListResponse>('/leave-requests', { query: { page } }),
  show: async (id: number) => (await request<{ data: LeaveRequest }>(`/leave-requests/${id}`)).data,
  create: (payload: LeavePayload) => {
    const { attachment, ...fields } = payload;
    return request<{ message: string; data: LeaveRequest }>('/leave-requests', {
      method: 'POST',
      form: toForm(fields, attachment),
      timeoutMs: 90000,
    });
  },
  cancel: (id: number) => request<{ message: string }>(`/leave-requests/${id}`, { method: 'DELETE' }),
};

export const overtime = {
  list: (page = 1) => request<Paginated<OvertimeRequest>>('/overtime-requests', { query: { page } }),
  show: async (id: number) => (await request<{ data: OvertimeRequest }>(`/overtime-requests/${id}`)).data,
  create: (payload: OvertimePayload) =>
    request<{ message: string; data: OvertimeRequest }>('/overtime-requests', { method: 'POST', json: payload }),
  cancel: (id: number) => request<{ message: string }>(`/overtime-requests/${id}`, { method: 'DELETE' }),
};

export const correction = {
  list: (page = 1) => request<Paginated<AttendanceCorrection>>('/attendance-corrections', { query: { page } }),
  show: async (id: number) => (await request<{ data: AttendanceCorrection }>(`/attendance-corrections/${id}`)).data,
  create: (payload: CorrectionPayload) => {
    const { attachment, ...fields } = payload;
    return request<{ message: string; data: AttendanceCorrection }>('/attendance-corrections', {
      method: 'POST',
      form: toForm(fields, attachment),
      timeoutMs: 90000,
    });
  },
  cancel: (id: number) => request<{ message: string }>(`/attendance-corrections/${id}`, { method: 'DELETE' }),
};

export const resignation = {
  list: (page = 1) => request<Paginated<Resignation>>('/resignations', { query: { page } }),
  show: async (id: number) => (await request<{ data: Resignation }>(`/resignations/${id}`)).data,
  create: (payload: ResignationPayload) =>
    request<{ message: string; data: Resignation }>('/resignations', { method: 'POST', json: payload }),
  cancel: (id: number) => request<{ message: string }>(`/resignations/${id}`, { method: 'DELETE' }),
};
