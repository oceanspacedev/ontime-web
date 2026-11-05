<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Company;
use App\Services\AnnualLeaveService;
use App\Support\StorageUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $profile = $this->employee ?? $this->intern ?? $this->freelancer;
        $annualLeave = app(AnnualLeaveService::class);
        $masterFacePhoto = $profile?->master_face_photo ? $this->formatStorageUrl($profile->master_face_photo) : null;
        $masterFaceVerifiedAt = $profile?->master_face_verified_at?->toISOString();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'master_face_photo' => $masterFacePhoto,
            'master_face_verified_at' => $masterFaceVerifiedAt,
            'roles' => $this->getRoleNames(),
            'permissions' => $this->getAllPermissions()->pluck('name')->values(),
            'employee' => $this->employee ? [
                'id' => $this->employee->id,
                'nip' => $this->employee->nip,
                'full_name' => $this->employee->full_name,
                'master_face_photo' => $this->formatStorageUrl($this->employee->master_face_photo),
                'master_face_verified_at' => $this->employee->master_face_verified_at?->toISOString(),
                'company' => $this->formatCompany($this->employee->company),
                'division' => $this->employee->division ? [
                    'id' => $this->employee->division->id,
                    'name' => $this->employee->division->name,
                ] : null,
                'job_title' => $this->employee->jobTitle ? [
                    'id' => $this->employee->jobTitle->id,
                    'name' => $this->employee->jobTitle->name,
                ] : null,
                'job_level' => $this->employee->jobLevel ? [
                    'id' => $this->employee->jobLevel->id,
                    'name' => $this->employee->jobLevel->name,
                ] : null,
                'has_annual_leave' => $annualLeave->isEligible($this->employee),
                'annual_leave_quota' => $annualLeave->quota($this->employee),
            ] : null,
            'intern' => $this->intern ? [
                'id' => $this->intern->id,
                'full_name' => $this->intern->full_name,
                'institution' => $this->intern->institution ?? $this->intern->school ?? 'Universitas/Instansi',
                'master_face_photo' => $this->formatStorageUrl($this->intern->master_face_photo),
                'master_face_verified_at' => $this->intern->master_face_verified_at?->toISOString(),
                'company' => $this->formatCompany($this->intern->company),
                'division' => $this->intern->division ? [
                    'id' => $this->intern->division->id,
                    'name' => $this->intern->division->name,
                ] : null,
                'mentor' => $this->intern->mentor ? [
                    'id' => $this->intern->mentor->id,
                    'full_name' => $this->intern->mentor->full_name ?? $this->intern->mentor->name ?? 'Mentor Terhubung',
                ] : null,
            ] : null,
            'freelancer' => $this->freelancer ? [
                'id' => $this->freelancer->id,
                'full_name' => $this->freelancer->full_name,
                'institution' => $this->freelancer->institution ?? $this->freelancer->project ?? 'External Contractor',
                'master_face_photo' => $this->formatStorageUrl($this->freelancer->master_face_photo),
                'master_face_verified_at' => $this->freelancer->master_face_verified_at?->toISOString(),
                'company' => $this->formatCompany($this->freelancer->company),
                'division' => $this->freelancer->division ? [
                    'id' => $this->freelancer->division->id,
                    'name' => $this->freelancer->division->name,
                ] : null,
                'supervisor' => $this->freelancer->supervisor ? [
                    'id' => $this->freelancer->supervisor->id,
                    'full_name' => $this->freelancer->supervisor->full_name ?? $this->freelancer->supervisor->name ?? 'Supervisor PIC',
                ] : null,
            ] : null,
        ];
    }

    private function formatStorageUrl(?string $path): ?string
    {
        return StorageUrl::for($path);
    }

    private function formatCompany(?Company $company): ?array
    {
        if (! $company) {
            return null;
        }

        return [
            'id' => $company->id,
            'name' => $company->name,
            'latitude' => $company->latitude ? (float) $company->latitude : null,
            'longitude' => $company->longitude ? (float) $company->longitude : null,
            'locations' => $company->locations ? $company->locations->map(fn ($loc) => [
                'id' => $loc->id,
                'name' => $loc->name,
                'latitude' => (float) $loc->latitude,
                'longitude' => (float) $loc->longitude,
                'radius_meters' => (int) $loc->radius_meters,
            ]) : [],
            'policy' => $company->policy ? [
                'require_gps' => (bool) $company->policy->require_gps,
                'require_photo' => (bool) $company->policy->require_photo,
                'require_face_recognition' => (bool) $company->policy->require_face_recognition,
                'late_tolerance_minutes' => (int) $company->policy->late_tolerance_minutes,
                'work_start_time' => $company->policy->work_start_time,
                'work_end_time' => $company->policy->work_end_time,
            ] : null,
        ];
    }
}
