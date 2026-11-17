import { request } from './client';
import type {
  ProcessApprovalResponse,
  SavedResponse,
  ShiftSwapColleague,
  ShiftSwapList,
  ShiftSwapPayload,
  ShiftSwapRequest,
} from './types';

export type ShiftSwapBox = 'all' | 'sent' | 'received';

/** Requests the worker sent, received, or both. */
export function list(box: ShiftSwapBox = 'all'): Promise<ShiftSwapList> {
  return request<ShiftSwapList>('/shift-swaps', { query: { box } });
}

export async function show(id: number): Promise<ShiftSwapRequest> {
  const response = await request<{ data: ShiftSwapRequest }>(`/shift-swaps/${id}`);
  return response.data;
}

/** Active colleagues of the worker's company, with their schedule on the date. */
export async function colleagues(date: string, search?: string): Promise<ShiftSwapColleague[]> {
  const response = await request<{ data: ShiftSwapColleague[] }>('/shift-swaps/colleagues', {
    query: { date, search: search?.trim() || undefined },
  });
  return response.data;
}

export function create(payload: ShiftSwapPayload): Promise<SavedResponse<ShiftSwapRequest>> {
  return request<SavedResponse<ShiftSwapRequest>>('/shift-swaps', { method: 'POST', json: payload });
}

export function accept(id: number): Promise<SavedResponse<ShiftSwapRequest>> {
  return request<SavedResponse<ShiftSwapRequest>>(`/shift-swaps/${id}/accept`, { method: 'POST' });
}

export function decline(id: number, reason?: string | null): Promise<SavedResponse<ShiftSwapRequest>> {
  return request<SavedResponse<ShiftSwapRequest>>(`/shift-swaps/${id}/decline`, { method: 'POST', json: { reason: reason?.trim() || null } });
}

export function cancel(id: number): Promise<SavedResponse<ShiftSwapRequest>> {
  return request<SavedResponse<ShiftSwapRequest>>(`/shift-swaps/${id}/cancel`, { method: 'POST' });
}

/** Approver decision, through the shared approval endpoint. */
export function process(
  id: number,
  action: 'approved' | 'rejected',
  note?: string | null,
  currentStep?: number | null,
): Promise<ProcessApprovalResponse> {
  return request<ProcessApprovalResponse>(`/approvals/shift_swap/${id}/process`, {
    method: 'PUT',
    json: { action, rejection_note: note || null, current_step: currentStep ?? null },
  });
}
