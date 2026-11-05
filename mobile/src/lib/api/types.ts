export type RoleName =
  | 'Superadmin'
  | 'Admin'
  | 'BOD'
  | 'Approver'
  | 'Employee'
  | 'Intern'
  | 'Freelancer'
  | (string & {});

export type AttendanceStatus =
  | 'on_time'
  | 'late'
  | 'absent'
  | 'leave'
  | 'holiday'
  | 'off'
  | 'pending_approval'
  | 'rejected';

export type RequestStatus = 'pending' | 'approved' | 'rejected' | (string & {});

export type LeaveType = 'annual_leave' | 'permission' | 'sick' | 'maternity' | 'unpaid';

export interface CompanyLocation {
  id: number;
  name: string;
  latitude: number;
  longitude: number;
  radius_meters: number;
}

export interface CompanyPolicy {
  require_gps: boolean;
  require_photo: boolean;
  require_face_recognition: boolean;
  late_tolerance_minutes: number;
  work_start_time: string | null;
  work_end_time: string | null;
}

export interface Company {
  id: number;
  name: string;
  latitude: number | null;
  longitude: number | null;
  locations: CompanyLocation[];
  policy: CompanyPolicy | null;
}

export interface NamedRef {
  id: number;
  name: string;
}

export interface PersonRef {
  id: number;
  full_name: string;
}

export interface EmployeeProfile {
  id: number;
  nip: string | null;
  full_name: string;
  master_face_photo: string | null;
  master_face_verified_at: string | null;
  company: Company | null;
  division: NamedRef | null;
  job_title: NamedRef | null;
  job_level: NamedRef | null;
}

export interface InternProfile {
  id: number;
  full_name: string;
  institution: string;
  master_face_photo: string | null;
  master_face_verified_at: string | null;
  company: Company | null;
  division: NamedRef | null;
  mentor: PersonRef | null;
}

export interface FreelancerProfile {
  id: number;
  full_name: string;
  institution: string;
  master_face_photo: string | null;
  master_face_verified_at: string | null;
  company: Company | null;
  division: NamedRef | null;
  supervisor: PersonRef | null;
}

export interface User {
  id: number;
  name: string;
  email: string;
  master_face_photo: string | null;
  master_face_verified_at: string | null;
  roles: RoleName[];
  permissions: string[];
  employee: EmployeeProfile | null;
  intern: InternProfile | null;
  freelancer: FreelancerProfile | null;
}

export interface LoginResponse {
  message: string;
  token_type: string;
  token: string;
  user: User;
}

export type OtpChannel = 'whatsapp' | 'email';

export interface OtpRequestResponse {
  message: string;
  channel: OtpChannel;
  challenge_id: string;
  /** Seconds the code and challenge stay valid. */
  expires_in: number;
}

export interface WorkerSummary {
  id: number;
  full_name: string;
  nip: string | null;
  company: NamedRef | null;
  division: NamedRef | null;
  master_face_photo: string | null;
}

export interface Attendance {
  id: number;
  employee_id: number | null;
  intern_id: number | null;
  freelancer_id: number | null;
  date: string;
  check_in: string | null;
  check_out: string | null;
  check_in_lat: string | null;
  check_in_lng: string | null;
  check_out_lat: string | null;
  check_out_lng: string | null;
  check_in_photo: string | null;
  check_out_photo: string | null;
  status: AttendanceStatus;
  is_corrected: boolean;
  is_out_of_bounds: boolean;
  is_face_verified: boolean | null;
  face_match_score: number | null;
  face_verification_notes: string | null;
  late_minutes: number | null;
  employee: WorkerSummary | null;
  intern: WorkerSummary | null;
  freelancer: WorkerSummary | null;
  user: { name: string; department: string } | null;
}

export interface TodaySchedule {
  shift: NamedRef | null;
  is_day_off: boolean;
  start_time: string;
  end_time: string;
  late_tolerance_minutes: number;
}

export interface TodayAttendance {
  date: string;
  has_checked_in: boolean;
  has_checked_out: boolean;
  attendance: Attendance | null;
  /** Jam kerja yang berlaku hari ini; absen pada backend lama. */
  schedule?: TodaySchedule | null;
}

export interface PaginationA {
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}

export interface PaginationB {
  current_page: number;
  last_page: number;
  total: number;
}

export interface Paginated<T, P = PaginationB> {
  data: T[];
  pagination: P;
}

interface RequestBase {
  id: number;
  employee_id: number | null;
  intern_id: number | null;
  freelancer_id: number | null;
  reason: string;
  status: RequestStatus;
  current_step: number | null;
  rejection_reason: string | null;
  created_at: string;
  updated_at: string;
  employee: WorkerSummary | null;
  intern: WorkerSummary | null;
  freelancer: WorkerSummary | null;
  user: { name: string; department: string } | null;
}

export interface LeaveRequest extends RequestBase {
  leave_type: LeaveType | string;
  leave_type_label: string;
  start_date: string;
  end_date: string;
  days_count: number | null;
  total_steps: number;
  attachment: string | null;
}

export interface OvertimeRequest extends RequestBase {
  date: string;
  start_time: string;
  end_time: string;
  duration_minutes: number | null;
  actual_duration_minutes: number | null;
  total_steps: number;
}

export interface AttendanceCorrection extends RequestBase {
  date: string;
  corrected_check_in: string | null;
  corrected_check_out: string | null;
  total_steps: number;
  attachment: string | null;
}

export interface Resignation extends RequestBase {
  employee_name: string | null;
  resignation_date: string;
  last_working_day: string;
  handover_notes: string | null;
}

export interface QuotaSummary {
  annual_leave_quota: number;
  used_days: number;
  remaining_days: number;
}

export interface LeaveListResponse extends Paginated<LeaveRequest> {
  quota_summary: QuotaSummary;
}

export interface PendingApprovals {
  leave_requests: LeaveRequest[];
  overtime_requests: OvertimeRequest[];
  attendance_corrections: AttendanceCorrection[];
  geofence_attendances: Attendance[];
  resignations: Resignation[];
}

export type ApprovalType = 'leave' | 'overtime' | 'correction' | 'geofence' | 'resignation';

export interface ProcessApprovalResponse {
  message: string;
  status: RequestStatus;
  current_step: number | null;
}

export interface LeaveCalendarEntry {
  id: number;
  person_name: string;
  user_name: string;
  company_name: string | null;
  division_name: string | null;
  department: string | null;
  leave_type: string;
  start_date: string;
  end_date: string;
  days_count: number;
  status: RequestStatus;
  reason: string;
  master_face_photo: string | null;
}

export interface LeaveCalendarResponse {
  month: number;
  year: number;
  data: LeaveCalendarEntry[];
}

export interface AttendanceReportResponse extends Paginated<Attendance, PaginationA> {
  start_date: string;
  end_date: string;
  summary: {
    total_present: number;
    total_late: number;
    total_leave: number;
    total_absent: number;
  };
}

/** A local file to send as one multipart field. */
export interface UploadFile {
  uri: string;
  name: string;
  type: string;
}
