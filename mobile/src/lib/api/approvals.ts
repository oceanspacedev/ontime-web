import { request } from './client';
import type { ApprovalType, PendingApprovals, ProcessApprovalResponse } from './types';

export function pending(): Promise<PendingApprovals> {
  return request<PendingApprovals>('/approvals/pending');
}

export function process(
  type: ApprovalType,
  id: number,
  action: 'approved' | 'rejected',
  note?: string | null,
): Promise<ProcessApprovalResponse> {
  return request<ProcessApprovalResponse>(`/approvals/${type}/${id}/process`, {
    method: 'PUT',
    json: { action, rejection_note: note || null },
  });
}
