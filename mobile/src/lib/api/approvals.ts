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
  /** The stage the approver saw; the server refuses when the request has moved on since. */
  currentStep?: number | null,
): Promise<ProcessApprovalResponse> {
  return request<ProcessApprovalResponse>(`/approvals/${type}/${id}/process`, {
    method: 'PUT',
    json: { action, rejection_note: note || null, current_step: currentStep ?? null },
  });
}
