import type { Company, LeaveType, User } from './api/types';

export interface LeaveTypeOption {
  value: LeaveType;
  label: string;
}

const ALL_LEAVE_TYPES: LeaveTypeOption[] = [
  { value: 'annual_leave', label: 'Cuti Tahunan' },
  { value: 'permission', label: 'Izin' },
  { value: 'sick', label: 'Sakit' },
  { value: 'maternity', label: 'Cuti Melahirkan' },
  { value: 'unpaid', label: 'Cuti Tanpa Gaji' },
];

export type ProfileKind = 'employee' | 'intern' | 'freelancer';

export interface Access {
  roles: string[];
  hasRole: (role: string) => boolean;
  hasPermission: (permission: string) => boolean;
  /** The worker profile linked to the account, if any. */
  profileKind: ProfileKind | null;
  profileName: string;
  company: Company | null;
  /** Presensi and requests need a worker profile. */
  canUseAttendance: boolean;
  canRequestLeave: boolean;
  canRequestOvertime: boolean;
  canRequestCorrection: boolean;
  canResign: boolean;
  canSeeLeaveCalendar: boolean;
  canApprove: boolean;
  /** Runs a team from the Tim tab: roster per outlet, schedules and freelancers. */
  canManageTeam: boolean;
  isSuperadmin: boolean;
  /** HR gave this employee annual leave; never true for interns and freelancers. */
  hasAnnualLeave: boolean;
  leaveTypes: LeaveTypeOption[];
  masterFacePhoto: string | null;
  masterFaceVerifiedAt: string | null;
}

/**
 * Mirrors section 4.1 of API.md: roles decide the menu, a worker profile unlocks presensi and
 * requests, and `permissions` only matter for accounts that are not plain workers.
 */
export function deriveAccess(user: User | null): Access {
  const roles = user?.roles ?? [];
  const permissions = new Set(user?.permissions ?? []);
  const hasRole = (role: string) => roles.includes(role);
  const hasPermission = (permission: string) => permissions.has(permission);

  const isSuperadmin = hasRole('Superadmin');
  const isWorkerRole = hasRole('Employee') || hasRole('Intern') || hasRole('Freelancer');
  const isApproverRole = hasRole('Approver') || hasRole('BOD') || hasRole('Admin') || hasRole('Koordinator') || isSuperadmin;

  const profile = user?.employee ?? user?.intern ?? user?.freelancer ?? null;
  const profileKind: ProfileKind | null = user?.employee ? 'employee' : user?.intern ? 'intern' : user?.freelancer ? 'freelancer' : null;
  const hasProfile = profileKind !== null;

  // Workers get their forms by role; other accounts need the matching Shield permission plus a profile.
  const requestAllowed = (permission: string) => hasProfile && (isWorkerRole || isSuperadmin || hasPermission(permission));

  // Annual leave is granted per employee by HR; interns and freelancers never have it.
  const hasAnnualLeave = profileKind === 'employee' && user?.employee?.has_annual_leave === true;
  const leaveTypes = profileKind === 'freelancer'
    ? ALL_LEAVE_TYPES.filter((type) => type.value === 'permission' || type.value === 'sick')
    : ALL_LEAVE_TYPES.filter((type) => type.value !== 'annual_leave' || hasAnnualLeave);

  return {
    roles,
    hasRole,
    hasPermission,
    profileKind,
    profileName: profile?.full_name ?? user?.name ?? '',
    company: profile?.company ?? null,
    canUseAttendance: hasProfile,
    canRequestLeave: requestAllowed('Create:LeaveRequest'),
    canRequestOvertime: requestAllowed('Create:OvertimeRequest'),
    canRequestCorrection: requestAllowed('Create:AttendanceCorrection'),
    canResign: profileKind === 'employee' && requestAllowed('Create:Resignation'),
    canSeeLeaveCalendar: !hasRole('Intern') || isApproverRole,
    canApprove: isApproverRole,
    canManageTeam: user?.access?.can_manage_team === true,
    isSuperadmin,
    hasAnnualLeave,
    leaveTypes,
    masterFacePhoto: profile?.master_face_photo ?? user?.master_face_photo ?? null,
    masterFaceVerifiedAt: profile?.master_face_verified_at ?? user?.master_face_verified_at ?? null,
  };
}
