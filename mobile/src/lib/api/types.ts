export type RoleName =
  | 'Superadmin'
  | 'Admin'
  | 'Koordinator'
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

/**
 * How a worker proves presence on the selfie side: no photo, a photo, a photo matched against the
 * master face, or an active head-turn challenge plus the face match.
 */
export type SelfieMode = 'none' | 'photo' | 'face' | 'liveness';

/** What the server does with a fake GPS or a failed face match: refuse it or send it to approval. */
export type PolicyFailAction = 'reject' | 'approval';

export interface CompanyPolicy {
  require_gps: boolean;
  require_photo: boolean;
  require_face_recognition: boolean;
  late_tolerance_minutes: number;
  work_start_time: string | null;
  work_end_time: string | null;
  /** The fields below are missing in responses from older servers. */
  require_liveness?: boolean;
  selfie_mode?: SelfieMode;
  mock_location_action?: PolicyFailAction;
  /** Minimum face similarity in percent. */
  face_match_threshold?: number;
  face_fail_action?: PolicyFailAction;
  /** Human readable mode, e.g. "GPS + Liveness + Wajah". */
  mode_label?: string;
}

export interface Company {
  id: number;
  name: string;
  latitude: number | null;
  longitude: number | null;
  locations: CompanyLocation[];
  /** Radius of the head office coordinates; missing on older servers (then 100 m). */
  geofence_radius_meters?: number;
  policy: CompanyPolicy | null;
}

/** An outlet with the geofence the server checks; older servers send only id and name. */
export interface OutletGeofence extends NamedRef {
  latitude?: number | null;
  longitude?: number | null;
  radius_meters?: number;
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
  /** Whether HR gave this employee annual leave. Missing in responses from older servers. */
  has_annual_leave?: boolean;
  /** Yearly annual leave quota in days, 0 without annual leave. */
  annual_leave_quota?: number;
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
  /** Missing on servers older than the team feature. */
  access?: UserAccess;
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

export interface MagicLinkRequestResponse {
  message: string;
  challenge_id: string;
  /** Seconds the emailed link and challenge stay valid. */
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
  /** The device reported a mocked (fake) GPS position. Missing on older servers. */
  is_mock_location?: boolean;
  is_face_verified: boolean | null;
  face_match_score: number | null;
  face_verification_notes: string | null;
  /** Result of the head-turn challenge, null when the policy did not ask for it. Missing on older servers. */
  liveness_verified?: boolean | null;
  liveness_notes?: string | null;
  late_minutes: number | null;
  /** Minutes the check-out fell before the scheduled end ("pulang cepat"); 0 or null when not early. Missing on older servers. */
  early_leave_minutes?: number | null;
  employee: WorkerSummary | null;
  intern: WorkerSummary | null;
  freelancer: WorkerSummary | null;
  user: { name: string; department: string } | null;
  /** The stage an approver sees; sent back on process so a stage that moved on meanwhile is refused. Missing on older servers. */
  current_step?: number | null;
  total_steps?: number | null;
  /** Why the approver rejected it, shown to the worker. Missing on older servers. */
  rejection_reason?: string | null;
  /** Checked in outside the schedule ("Masuk di luar jadwal"). Missing on older servers. */
  is_off_schedule?: boolean;
  /** The rostered colleague an off-schedule check-in replaced, if named. Missing on older servers. */
  replaced_worker?: { key: string; name: string } | null;
  /** Ready label for approvers and history, e.g. "Masuk di luar jadwal · menggantikan Budi · shift Closing". */
  off_schedule_label?: string | null;
  /**
   * Part (1 or 2) of a split shift. This and the fields below come from the schedule the row was checked
   * in to; they are missing on older servers.
   */
  segment?: number;
  schedule_id?: number | null;
  schedule_sequence?: number;
  shift?: { id: number | null; name: string; code: string | null } | null;
  outlet?: { id: number; name: string | null } | null;
  /** "HH:MM" of the schedule that applied, null without hours. */
  scheduled_start?: string | null;
  scheduled_end?: string | null;
  /** Minutes actually worked, the number the reports add up. */
  worked_minutes?: number;
  normal_minutes?: number | null;
  /** Automatic overtime above the shift's normal hours. */
  overtime_minutes?: number;
  /** Late minutes as the reports count them (0 for a rejected or off-schedule row). */
  reported_late_minutes?: number;
  /** Checked in, but the check-out was never recorded and its window has closed ("Belum absen pulang"). */
  is_missing_check_out?: boolean;
  pending_punch?: string | null;
  /** Ready label, e.g. "Pagi · 08:00–16:00 · JChicken Ciledug". */
  schedule_label?: string | null;
}

export type AttendancePurpose = 'check_in' | 'check_out';

export interface AttendanceCheckResponse {
  /** Already says why the record waits for approval, when it does. */
  message: string;
  attendance: Attendance;
  /** Missing on older servers. */
  requires_approval?: boolean;
  approval_reasons?: string[];
}

export type LivenessStepKey = 'front' | 'left' | 'right';

export interface LivenessStep {
  key: LivenessStepKey;
  /** Short title, e.g. "Tengok ke kiri". */
  label: string;
  /** One sentence telling the user how to move. */
  instruction: string;
}

/** A single-use head-turn challenge; frames are sent in the order of `steps`. */
export interface LivenessChallenge {
  challenge_id: string;
  steps: LivenessStep[];
  expires_at: string;
  /** Seconds the challenge stays valid. */
  expires_in: number;
}

/** One working segment of a day, "HH:MM". A split shift has two. */
export interface ShiftSegment {
  start: string;
  end: string;
}

export interface TodaySchedule {
  shift: (NamedRef & { code?: string | null; talenta_code?: string | null }) | null;
  is_day_off: boolean;
  /** Segment (1 or 2) the worker is on now; start_time/end_time are that segment's hours. */
  segment?: number;
  segment_count?: number;
  /** Every segment of today's shift, to show a split shift as "07:45–13:00 & 17:45–22:00". */
  segments?: ShiftSegment[];
  start_time: string;
  end_time: string;
  late_tolerance_minutes: number;
  /** Outlet the worker is scheduled at today; check-in is only accepted there. */
  outlet?: OutletGeofence | null;
  section?: string | null;
  /** A check-in today is "Masuk di luar jadwal" (day off, or a freelancer without a shift). Missing on older servers. */
  is_off_schedule?: boolean;
}

/** One schedule of the day (newer servers), ordered by start; a day may have several. */
export interface TodayScheduleItem {
  /** Null for standing company hours without a roster row. */
  schedule_id: number | null;
  sequence: number;
  segment: number;
  /** The session the top-level fields (and the Masuk / Pulang buttons) describe. */
  is_current: boolean;
  is_day_off: boolean;
  shift: { id: number; name: string; code: string | null } | null;
  outlet: OutletGeofence | null;
  section: string | null;
  /** "HH:MM", null on a day off. */
  start_time: string | null;
  end_time: string | null;
  crosses_midnight: boolean;
  late_tolerance_minutes: number | null;
  normal_minutes: number | null;
  /** Roster vocabulary: hadir, terlambat, pulang, menunggu, belum, nanti, libur, cuti, ditolak. */
  state: RosterState;
  /** ISO time after which a check-out is no longer accepted. */
  check_out_closes_at: string | null;
  attendance: Attendance | null;
}

export interface DayTotals {
  worked_minutes: number;
  overtime_minutes: number;
  late_minutes: number;
}

export interface TodayAttendance {
  date: string;
  has_checked_in: boolean;
  has_checked_out: boolean;
  attendance: Attendance | null;
  /** Every row of the business date. Missing on older servers. */
  attendances?: Attendance[];
  /** Every schedule of the day with its attendance. Missing on older servers. */
  schedules?: TodayScheduleItem[];
  /** False when the worker has no schedule to work today (day off, not rostered). Missing on older servers. */
  is_scheduled?: boolean;
  /** The business date the card describes: the current session's, which is yesterday while last night's shift is still on. Missing on older servers. */
  session_date?: string;
  /** Like is_scheduled, for session_date instead of the calendar day. Missing on older servers. */
  is_session_scheduled?: boolean;
  /** What the day's attendance adds up to so far. Missing on older servers. */
  day_totals?: DayTotals;
  /** Jam kerja yang berlaku hari ini; absen pada backend lama. */
  schedule?: TodaySchedule | null;
  /** Outlet the open attendance was checked in at; check-out is only accepted there. */
  check_out_outlet?: OutletGeofence | null;
  /** Today is covered by an approved leave: check-in would be refused, so the app does not offer it. Missing on older servers. */
  is_on_leave?: boolean;
  /** Yesterday's shift was checked in but never checked out; the app points to a correction. Missing on older servers. */
  unclosed_previous?: UnclosedPrevious | null;
}

export interface UnclosedPrevious {
  date: string;
  check_in: string | null;
  shift: string | null;
  /** A correction for that date already waits for approval, so no second one is offered. */
  correction_pending: boolean;
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
  /** The fields below are missing on older servers. */
  attendance_id?: number | null;
  schedule_id?: number | null;
  sequence?: number | null;
  segment?: number | null;
  /** The corrected check-out falls on the day after `date` (a shift past midnight). */
  check_out_next_day?: boolean;
  corrected_shift_id?: number | null;
  corrected_company_location_id?: number | null;
  /** "worker" for a request, "admin" for an edit recorded by an admin. */
  origin?: string;
}

export interface Resignation extends RequestBase {
  employee_name: string | null;
  resignation_date: string;
  last_working_day: string;
  handover_notes: string | null;
}

export interface QuotaSummary {
  /** False for interns, freelancers and employees without annual leave. Missing on older servers. */
  eligible?: boolean;
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
  /** Tukar Shift requests accepted by the colleague and waiting on the approver's stage. Older servers omit it. */
  shift_swaps?: ShiftSwapRequest[];
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
    /** Missing on older servers. */
    total_pending?: number;
    total_missing_check_out?: number;
    total_worked_hours?: number;
    total_overtime_hours?: number;
  };
}

/** A local file to send as one multipart field. */
export interface UploadFile {
  uri: string;
  name: string;
  type: string;
}

export interface UserAccess {
  /** Runs a team: roster, schedules and freelancers (Koordinator, Admin, Superadmin). */
  can_manage_team: boolean;
  is_coordinator: boolean;
  /** CESA food waste report link, for coordinators and F&B crew; null for everyone else. */
  waste_url?: string | null;
  /** Kelola Klien from the app; missing on servers older than the client admin feature. */
  client_admin?: ClientAdminAccess;
}

export interface ClientAdminAccess {
  is_pic: boolean;
  can_manage_companies: boolean;
  can_manage_accounts: boolean;
}

export type WorkerType = 'employee' | 'intern' | 'freelancer';

/** `cuti`: on approved leave and not checked in, so not counted as missing. */
/** `jeda`: between the two segments of a split shift (segment 1 done, segment 2 still to come). */
export type RosterState = 'hadir' | 'terlambat' | 'pulang' | 'menunggu' | 'belum' | 'nanti' | 'libur' | 'cuti' | 'jeda' | 'ditolak';

export interface RosterEntry {
  /** Null for someone listed without a schedule ("Tanpa jadwal", or still on last night's shift). */
  schedule_id: number | null;
  worker: { type: WorkerType; id: number; company_id: number; number: string; name: string };
  shift: { id: number; name: string; code: string | null; label: string; start_time: string; end_time: string; talenta_code?: string | null; segments?: ShiftSegment[] } | null;
  location_id: number | null;
  /** Brand of the worker when this day is spent at another brand's outlet. */
  borrowed_from?: string | null;
  section: string | null;
  state: RosterState;
  check_in: string | null;
  check_out: string | null;
  late_minutes: number;
  /** One item per segment worked so far (two for a finished split shift). */
  attendances?: { segment: number; check_in: string | null; check_out: string | null; late_minutes: number }[];
  /** False for a worker lent in from another brand: shown here, but planned by their own brand. */
  can_edit?: boolean;
  /** What explains the state: "Jeda · segmen 2 mulai 17:45", "Shift semalam (16 Nov) · belum absen pulang", "Tanpa jadwal". */
  note?: string | null;
  /** The fields below are missing on older servers. */
  outlet_name?: string | null;
  overtime_minutes?: number;
  /** The schedule has attendance, so it is changed by a correction, not here. */
  locked?: boolean;
  /** How many schedules the worker has that date (this entry is one of them). */
  schedule_count?: number;
  is_missing_check_out?: boolean;
}

export interface WeekCell {
  schedule_id: number;
  is_day_off: boolean;
  shift: { id: number; name: string; code: string | null; talenta_code: string | null; label: string; segments: ShiftSegment[] } | null;
  location_id: number | null;
  outlet: string | null;
  section: string | null;
  borrowed_from?: string | null;
  /** The fields below are missing on older servers. */
  sequence?: number;
  /** How many further schedules the worker has that date (the cell shows the first). */
  more?: number;
  /** The schedule has attendance and cannot be changed from the roster. */
  locked?: boolean;
  /** The date is charged to an approved leave. */
  on_leave?: boolean;
}

/** One week (Monday to Sunday): a line per worker, a cell per date that has a schedule. */
export interface TeamWeek {
  start: string;
  end: string;
  days: string[];
  /** `can_edit` is false for a worker lent in from another brand. */
  workers: {
    worker: RosterEntry['worker'];
    days: Record<string, WeekCell>;
    can_edit?: boolean;
    /** Every schedule of each date, in day order. Missing on older servers. */
    day_schedules?: Record<string, WeekCell[]>;
  }[];
}

export interface RosterOutlet {
  location: { id: number; name: string } | null;
  /** Set on groups of people who are not on the day's schedule: checked in without one, or still on last night's shift. */
  kind?: 'unscheduled' | 'overnight';
  /** `cuti`, `jeda` and `checked_in` (scheduled people with a check-in of the day) may be missing from an older server. */
  counts: { scheduled: number; cuti?: number; jeda?: number; checked_in?: number } & Record<Exclude<RosterState, 'cuti' | 'jeda' | 'ditolak'>, number> & { ditolak?: number };
  entries: RosterEntry[];
}

export interface TeamRoster {
  date: string;
  outlets: RosterOutlet[];
  /** Distinct people, so a person on two outlets counts once. Missing on older servers. */
  summary?: { people_scheduled: number; people_checked_in: number };
}

export interface TeamShift {
  id: number;
  name: string;
  code: string | null;
  brand: string | null;
  label: string;
  start_time: string;
  end_time: string;
  talenta_code?: string | null;
  segments?: ShiftSegment[];
}

export interface TeamOutlet {
  id: number;
  name: string;
  brand: string | null;
  /** False for another brand's outlet: a worker can be lent there for a day, not placed there. */
  own_brand?: boolean;
}

export interface TeamCompany {
  id: number;
  name: string;
  outlets: TeamOutlet[];
  shifts: TeamShift[];
  sections: string[];
}

export interface TeamOptions {
  companies: TeamCompany[];
}

export interface TeamWorker {
  type: WorkerType;
  id: number;
  company_id: number;
  number: string;
  name: string;
}

export type ScheduleMode = 'shift' | 'off' | 'clear';

export interface ScheduleChange {
  worker_type: WorkerType;
  worker_id: number;
  date: string;
  mode: ScheduleMode;
  shift_id?: number | null;
  company_location_id?: number | null;
  section?: string | null;
  /** Edit or clear exactly this schedule of a day that has several. Ignored by older servers. */
  schedule_id?: number | null;
  /** Add a further schedule to the day instead of changing it. */
  add?: boolean;
  /** Why a past date is changed; logged with the change. */
  reason?: string | null;
}

/** One schedule as PUT /team/schedules answers it. */
export interface SavedSchedule {
  id: number;
  date: string;
  is_day_off: boolean;
  shift_id: number | null;
  company_location_id: number | null;
  section: string | null;
  sequence?: number;
  locked?: boolean;
}

export interface ScheduleSaveResponse {
  message: string;
  data: SavedSchedule | null;
  /** Every schedule of the date after the change. Missing on older servers. */
  schedules?: SavedSchedule[];
}

export interface TeamFreelancer {
  id: number;
  company_id: number;
  number: string;
  name: string;
  phone: string | null;
  brand: string | null;
  status: string;
  /** True until the contract has ended, so a contract that has not started yet is still "active". */
  is_active: boolean;
  has_account: boolean;
  start_date: string | null;
  end_date: string | null;
  has_started?: boolean;
  contract_state?: 'not_started' | 'active' | 'ended';
  /** "Belum mulai", "Aktif" or "Berakhir". */
  contract_state_label?: string;
}

export interface NewFreelancer {
  full_name: string;
  phone: string;
  company_location_id: number | null;
  start_date: string;
}

export interface NewAccount {
  email: string;
  password: string;
  whatsapp_sent: boolean;
  whatsapp_url: string | null;
}

export interface NewFreelancerResponse {
  message: string;
  data: TeamFreelancer;
  account: NewAccount | null;
}

export interface TeamRecapRow {
  worker: { type: WorkerType; id: number; number: string; name: string; company: string | null; division: string | null };
  outlet: string | null;
  scheduled_days: number;
  present_days: number;
  /** Present days whose check-in still waits for approval (not final for pay). Missing on older servers. */
  pending_days?: number;
  late_days: number;
  late_minutes: number;
  /** Days checked out before the scheduled end ("pulang cepat"). Missing on older servers. */
  early_leave_days?: number;
  early_leave_minutes?: number;
  /** Approved overtime (lembur) in the month: requests and hours. Missing on older servers. */
  overtime_count?: number;
  overtime_hours?: number;
  absent_days: number;
  off_days: number;
  scheduled_hours: number;
  worked_hours: number;
  /** The fields below are missing on older servers. */
  leave_days?: number;
  missing_check_out_days?: number;
  incomplete_days?: number;
  sessions_scheduled?: number;
  sessions_worked?: number;
  sessions_missed?: number;
  normal_hours?: number;
  auto_overtime_hours?: number;
  request_overtime_hours?: number;
  scheduled_days_to_date?: number;
  scheduled_hours_to_date?: number;
  overtime_unattended_count?: number;
}

export interface TeamRecap {
  month: string;
  data: TeamRecapRow[];
}

/* -------------------------------------------------------------------------------------------------
 * Kelola Klien: a PIC or Admin runs their Klien's companies, policies, locations and accounts.
 * -----------------------------------------------------------------------------------------------*/

export interface ClientPermissions {
  is_pic: boolean;
  can_manage_companies: boolean;
  can_create_company: boolean;
  can_manage_accounts: boolean;
}

export interface ClientTenant {
  id: number;
  name: string;
}

export interface CompanySummary {
  id: number;
  name: string;
  code: string;
  email: string | null;
  phone: string | null;
  address: string | null;
  latitude: number | null;
  longitude: number | null;
  is_active: boolean;
  mode_label: string;
  locations_count: number;
  divisions_count: number;
  /** Employees, interns and freelancers together. */
  workers_count: number;
}

export interface ClientOverview {
  permissions: ClientPermissions;
  tenant: ClientTenant | null;
  companies: CompanySummary[];
}

export interface CompanyInput {
  name: string;
  code: string;
  email?: string | null;
  phone?: string | null;
  address?: string | null;
  latitude?: number | null;
  longitude?: number | null;
  is_active?: boolean;
}

export type MockLocationAction = PolicyFailAction;
export type FaceFailAction = PolicyFailAction;
export type PolicySelfieMode = SelfieMode;

export interface ClientPolicy {
  late_tolerance_minutes: number;
  require_gps: boolean;
  geofence_radius_meters: number;
  mock_location_action: MockLocationAction;
  selfie_mode: PolicySelfieMode;
  face_match_threshold: number;
  face_fail_action: FaceFailAction;
  annual_leave_quota: number;
  default_approval_stages: number;
  /** HH:MM */
  work_start_time: string;
  /** HH:MM */
  work_end_time: string;
  mode_label: string;
}

export type ClientPolicyInput = Omit<ClientPolicy, 'mode_label'>;

export interface OptionItem<T extends string = string> {
  value: T;
  label: string;
}

export interface SelfieModeOption extends OptionItem<PolicySelfieMode> {
  description: string;
  /** False when the server cannot offer it, like liveness without CompreFace. */
  available: boolean;
}

export interface PolicyOptions {
  selfie_modes: SelfieModeOption[];
  mock_location_actions: OptionItem<MockLocationAction>[];
  face_fail_actions: OptionItem<FaceFailAction>[];
}

export interface ClientPolicyResponse {
  data: ClientPolicy;
  options: PolicyOptions;
  /** GPS can only be required once the company has a location. */
  has_locations: boolean;
}

export interface ClientLocation {
  id: number;
  company_id: number;
  division_id: number | null;
  name: string;
  address: string | null;
  latitude: number;
  longitude: number;
  radius_meters: number | null;
  is_active: boolean;
}

export interface LocationInput {
  name: string;
  address?: string | null;
  latitude: number;
  longitude: number;
  radius_meters?: number | null;
  division_id?: number | null;
  is_active?: boolean;
}

export interface ClientDivision {
  id: number;
  company_id: number;
  parent_id: number | null;
  name: string;
  code: string | null;
  is_active: boolean;
  /** "Parent > Child" */
  path: string;
}

export interface DivisionInput {
  name: string;
  code?: string | null;
  parent_id?: number | null;
  is_active?: boolean;
}

export type DataScope = 'all' | 'companies' | 'divisions' | 'own';

export interface ClientAccount {
  id: number;
  name: string;
  email: string;
  phone: string | null;
  is_active: boolean;
  roles: string[];
  data_scope: DataScope;
  data_scope_label: string;
  companies: NamedRef[];
  divisions: NamedRef[];
  worker: { type: WorkerType; id: number; name: string; company: string | null } | null;
  can_edit: boolean;
}

export interface AccountInput {
  name: string;
  /** Only sent when creating; the email cannot be changed afterwards. */
  email?: string;
  phone?: string | null;
  roles: string[];
  data_scope: DataScope;
  company_ids?: number[];
  division_ids?: number[];
  /** Only sent when updating. */
  is_active?: boolean;
}

export type NewAccountInput = AccountInput & { email: string };
export type UpdateAccountInput = Omit<AccountInput, 'email'> & { is_active: boolean };

export interface DataScopeOption extends OptionItem<DataScope> {
  description: string;
}

export interface AccountOptions {
  roles: string[];
  scopes: DataScopeOption[];
  companies: NamedRef[];
  divisions: { id: number; company_id: number; label: string }[];
}

export interface AccountCredentials {
  email: string;
  password: string;
  whatsapp_sent: boolean;
  wa_url: string | null;
}

export interface ClientAccountsPagination {
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}

export type ClientAccountList = Paginated<ClientAccount, ClientAccountsPagination>;

export interface SavedResponse<T> {
  message: string;
  data: T;
}

export interface NewAccountResponse extends SavedResponse<ClientAccount> {
  credentials: AccountCredentials;
}

export interface ResetPasswordResponse {
  message: string;
  credentials: AccountCredentials;
}

/** Tukar Shift: "swap" trades both workers' schedules on the dates; "give_away" hands the requester's shift to a colleague who is off. */
export type ShiftSwapType = 'swap' | 'give_away';

export type ShiftSwapStatus =
  | 'awaiting_colleague'
  | 'pending'
  | 'approved'
  | 'rejected'
  | 'declined'
  | 'cancelled'
  | 'expired'
  | 'failed';

export type WorkerKind = 'employee' | 'intern' | 'freelancer';

export interface ShiftSwapWorker {
  type: WorkerKind;
  id: number;
  full_name: string;
  division: string | null;
}

/** What each worker had on a date when the request was made, and what they get after the swap. */
export interface ShiftSwapDay {
  date: string;
  requester_before: string;
  requester_after: string;
  counterpart_before: string;
  counterpart_after: string;
}

export interface ShiftSwapStep {
  step_order: number;
  step_name: string;
  status: string;
  notes: string | null;
  actioned_at: string | null;
}

export interface ShiftSwapRequest {
  id: number;
  type: ShiftSwapType;
  requester_date: string;
  counterpart_date: string | null;
  reason: string | null;
  status: ShiftSwapStatus;
  status_label: string;
  current_step: number | null;
  rejection_reason: string | null;
  failure_reason: string | null;
  counterpart_responded_at: string | null;
  applied_at: string | null;
  created_at: string | null;
  updated_at: string | null;
  requester: ShiftSwapWorker | null;
  counterpart: ShiftSwapWorker | null;
  employee_name: string | null;
  days: ShiftSwapDay[];
  is_mine: boolean;
  is_for_me: boolean;
  can_respond: boolean;
  can_cancel: boolean;
  approval_steps: ShiftSwapStep[];
}

export interface ShiftSwapList {
  data: ShiftSwapRequest[];
  awaiting_my_answer: number;
  pagination: { current_page: number; last_page: number; total: number };
}

/** A colleague who can be asked, with their schedule on the chosen date. */
export interface ShiftSwapColleague {
  type: WorkerKind;
  id: number;
  full_name: string;
  division: string | null;
  is_off: boolean;
  schedule_label: string;
}

export interface ShiftSwapPayload {
  type: ShiftSwapType;
  requester_date: string;
  counterpart_date?: string | null;
  colleague_type: WorkerKind;
  colleague_id: number;
  reason?: string | null;
}

/** An active shift of the worker's company that an off-schedule check-in ("Masuk di luar jadwal") can cover. */
export interface CoverShiftOption {
  id: number;
  name: string;
  code?: string | null;
  start_time: string;
  end_time: string;
  segments?: ShiftSegment[];
  crosses_midnight?: boolean;
}

/** A colleague of the same company rostered today, who an off-schedule check-in can replace. */
export interface CoverColleague {
  /** "employee:12", "intern:3" or "freelancer:7"; sent back as `replaces_worker`. */
  key: string;
  name: string;
  type: 'employee' | 'intern' | 'freelancer';
  shift: { id: number; name: string; start_time: string; end_time: string } | null;
  outlet: { id: number; name: string } | null;
  has_checked_in: boolean;
}

/** GET /attendance/cover-options: what the app offers for "Masuk di luar jadwal" today. */
export interface CoverOptions {
  date: string;
  /** A check-in now is outside the worker's schedule and waits for approval. */
  is_off_schedule: boolean;
  /** Why: a rostered day off, or (freelancers) no shift at all. */
  off_schedule_reason: 'day_off' | 'no_schedule' | null;
  shifts: CoverShiftOption[];
  colleagues: CoverColleague[];
  /** Outlets the cover may be booked at. Missing on older servers. */
  outlets?: NamedRef[];
  /** The worker's own default shift, to pre-select. Missing on older servers. */
  default_shift_id?: number | null;
}
