import type { PendingApprovals } from './api/types';

export function countPending(pending: PendingApprovals | undefined): number {
  if (!pending) {
    return 0;
  }
  return (
    pending.leave_requests.length +
    pending.overtime_requests.length +
    pending.attendance_corrections.length +
    pending.geofence_attendances.length +
    pending.resignations.length
  );
}
