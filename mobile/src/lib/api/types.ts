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
  employee: WorkerSummary | null;
  intern: WorkerSummary | null;
  freelancer: WorkerSummary | null;
  user: { name: string; department: string } | null;
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
}

export interface TodayAttendance {
  date: string;
  has_checked_in: boolean;
  has_checked_out: boolean;
  attendance: Attendance | null;
  /** Jam kerja yang berlaku hari ini; absen pada backend lama. */
  schedule?: TodaySchedule | null;
  /** Outlet the open attendance was checked in at; check-out is only accepted there. */
  check_out_outlet?: OutletGeofence | null;
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

export type RosterState = 'hadir' | 'terlambat' | 'pulang' | 'menunggu' | 'belum' | 'nanti' | 'libur';

export interface RosterEntry {
  schedule_id: number;
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
}

export interface WeekCell {
  schedule_id: number;
  is_day_off: boolean;
  shift: { id: number; name: string; code: string | null; talenta_code: string | null; label: string; segments: ShiftSegment[] } | null;
  location_id: number | null;
  outlet: string | null;
  section: string | null;
  borrowed_from?: string | null;
}

/** One week (Monday to Sunday): a line per worker, a cell per date that has a schedule. */
export interface TeamWeek {
  start: string;
  end: string;
  days: string[];
  workers: { worker: RosterEntry['worker']; days: Record<string, WeekCell> }[];
}

export interface RosterOutlet {
  location: { id: number; name: string } | null;
  counts: { scheduled: number } & Record<RosterState, number>;
  entries: RosterEntry[];
}

export interface TeamRoster {
  date: string;
  outlets: RosterOutlet[];
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
}

export interface TeamFreelancer {
  id: number;
  company_id: number;
  number: string;
  name: string;
  phone: string | null;
  brand: string | null;
  status: string;
  is_active: boolean;
  has_account: boolean;
  start_date: string | null;
  end_date: string | null;
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
  late_days: number;
  late_minutes: number;
  absent_days: number;
  off_days: number;
  scheduled_hours: number;
  worked_hours: number;
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
