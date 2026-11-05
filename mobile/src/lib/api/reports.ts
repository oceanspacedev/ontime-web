import { request } from './client';
import type { AttendanceReportResponse, LeaveCalendarResponse } from './types';

export function leaveCalendar(month: number, year: number): Promise<LeaveCalendarResponse> {
  return request<LeaveCalendarResponse>('/kalender-cuti', { query: { month, year } });
}

export function attendanceReport(startDate: string, endDate: string, page = 1): Promise<AttendanceReportResponse> {
  return request<AttendanceReportResponse>('/laporan-absensi', {
    query: { start_date: startDate, end_date: endDate, page },
  });
}
