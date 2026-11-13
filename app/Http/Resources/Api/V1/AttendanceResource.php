<?php

namespace App\Http\Resources\Api\V1;

use App\Support\StorageUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AttendanceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $profile = $this->employee ?? $this->intern ?? $this->freelancer;

        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'intern_id' => $this->intern_id,
            'freelancer_id' => $this->freelancer_id,
            'date' => $this->date?->format('Y-m-d'),
            'segment' => (int) ($this->segment ?? 1),
            'check_in' => $this->check_in,
            'check_out' => $this->check_out,
            'check_in_lat' => $this->check_in_lat,
            'check_in_lng' => $this->check_in_lng,
            'check_out_lat' => $this->check_out_lat,
            'check_out_lng' => $this->check_out_lng,
            'check_in_photo' => $this->formatStorageUrl($this->check_in_photo),
            'check_out_photo' => $this->formatStorageUrl($this->check_out_photo),
            'status' => $this->status,
            'is_corrected' => (bool) $this->is_corrected,
            'is_out_of_bounds' => (bool) $this->is_out_of_bounds,
            'is_mock_location' => (bool) $this->is_mock_location,
            'is_face_verified' => $this->is_face_verified,
            'face_match_score' => $this->face_match_score,
            'face_verification_notes' => $this->face_verification_notes,
            'liveness_verified' => $this->liveness_verified,
            'liveness_notes' => $this->liveness_notes,
            'late_minutes' => $this->late_minutes,
            'employee' => $this->employee ? $this->formatWorkerProfile($this->employee) : null,
            'intern' => $this->intern ? $this->formatWorkerProfile($this->intern) : null,
            'freelancer' => $this->freelancer ? $this->formatWorkerProfile($this->freelancer) : null,
            'user' => $profile ? [
                'name' => $profile->full_name ?? 'Pengguna',
                'department' => $profile->division?->name ?? $profile->department ?? 'Staf',
            ] : null,
        ];
    }

    private function formatWorkerProfile($profile): array
    {
        return [
            'id' => $profile->id,
            'full_name' => $profile->full_name ?? 'Pengguna',
            'nip' => $profile->nip ?? $profile->employee_code ?? null,
            'company' => $profile->company ? [
                'id' => $profile->company->id,
                'name' => $profile->company->name,
            ] : null,
            'division' => $profile->division ? [
                'id' => $profile->division->id,
                'name' => $profile->division->name,
            ] : null,
            'master_face_photo' => $profile->master_face_photo ?? null,
        ];
    }

    private function formatStorageUrl(?string $path): ?string
    {
        return StorageUrl::for($path);
    }
}
