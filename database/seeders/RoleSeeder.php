<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Generate Filament Shield permissions
        Artisan::call('shield:generate', [
            '--all' => true,
            '--option' => 'permissions',
            '--panel' => 'admin',
            '--no-interaction' => true,
        ]);

        // 2. Ensure Roles Exist
        $roles = [
            'Superadmin',
            'Admin',
            'BOD',
            'Approver',
            'Employee',
            'Intern',
            'Freelancer',
        ];

        $roleModels = [];
        foreach ($roles as $roleName) {
            $roleModels[$roleName] = Role::firstOrCreate([
                'name' => $roleName,
                'guard_name' => 'web',
            ]);
        }

        // 3. Assign Permissions to Intern Role
        $internPermissions = [
            'View:AbsenHariIni',
            'ViewAny:AttendanceCorrection',
            'View:AttendanceCorrection',
            'Create:AttendanceCorrection',
            'ViewAny:LeaveRequest',
            'View:LeaveRequest',
            'Create:LeaveRequest',
            'Update:LeaveRequest',
            'ViewAny:OvertimeRequest',
            'View:OvertimeRequest',
            'Create:OvertimeRequest',
            'Update:OvertimeRequest',
            'View:LaporanAbsensi',
            'View:UserProfileWidget',
        ];
        $roleModels['Intern']->syncPermissions(
            Permission::whereIn('name', $internPermissions)->get()
        );

        // 4. Assign Permissions to Freelancer Role
        $freelancerPermissions = [
            'View:AbsenHariIni',
            'ViewAny:AttendanceCorrection',
            'View:AttendanceCorrection',
            'Create:AttendanceCorrection',
            'ViewAny:LeaveRequest',
            'View:LeaveRequest',
            'Create:LeaveRequest',
            'Update:LeaveRequest',
            'ViewAny:OvertimeRequest',
            'View:OvertimeRequest',
            'Create:OvertimeRequest',
            'Update:OvertimeRequest',
            'View:LaporanAbsensi',
            'View:UserProfileWidget',
        ];
        $roleModels['Freelancer']->syncPermissions(
            Permission::whereIn('name', $freelancerPermissions)->get()
        );

        // 5. Assign Permissions to Employee Role
        $employeePermissions = [
            'View:AbsenHariIni',
            'ViewAny:AttendanceCorrection',
            'View:AttendanceCorrection',
            'Create:AttendanceCorrection',
            'Update:AttendanceCorrection',
            'ViewAny:LeaveRequest',
            'View:LeaveRequest',
            'Create:LeaveRequest',
            'Update:LeaveRequest',
            'ViewAny:OvertimeRequest',
            'View:OvertimeRequest',
            'Create:OvertimeRequest',
            'Update:OvertimeRequest',
            'ViewAny:Resignation',
            'View:Resignation',
            'Create:Resignation',
            'View:KalenderCuti',
            'View:LaporanAbsensi',
            'View:UserProfileWidget',
        ];
        $roleModels['Employee']->syncPermissions(
            Permission::whereIn('name', $employeePermissions)->get()
        );

        // 6. Assign Permissions to Approver & BOD Roles
        $approverPermissions = [
            'View:AbsenHariIni',
            'View:ApprovalSaya',
            'ViewAny:AttendanceCorrection',
            'View:AttendanceCorrection',
            'Update:AttendanceCorrection',
            'ViewAny:LeaveRequest',
            'View:LeaveRequest',
            'Update:LeaveRequest',
            'ViewAny:OvertimeRequest',
            'View:OvertimeRequest',
            'Update:OvertimeRequest',
            'View:KalenderCuti',
            'View:LaporanAbsensi',
            'View:UserProfileWidget',
            'View:ApproverPendingWidget',
        ];
        $roleModels['Approver']->syncPermissions(
            Permission::whereIn('name', $approverPermissions)->get()
        );
        $roleModels['BOD']->syncPermissions(
            Permission::whereIn('name', $approverPermissions)->get()
        );

        // 7. Superadmin gets all permissions
        $roleModels['Superadmin']->syncPermissions(Permission::all());
    }
}
