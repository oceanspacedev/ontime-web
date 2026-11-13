<?php

namespace App\Http\Resources\Api\V1;

use App\Models\LeaveRequest;
use App\Support\StorageUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LeaveRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $profile = $this->employee ?? $this->intern ?? $this->freelancer;

        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'intern_id' => $this->intern_id ?? null,
            'freelancer_id' => $this->freelancer_id ?? null,
            'leave_type' => $this->leave_type,
            'leave_type_label' => LeaveRequest::typeLabel($this->leave_type),
            'start_date' => $this->start_date?->format('Y-m-d'),
            'end_date' => $this->end_date?->format('Y-m-d'),
            'days_count' => $this->days_count ?? null,
            'reason' => $this->reason,
            'status' => $this->status,
            'current_step' => $this->current_step,
            'total_steps' => $this->approval_steps_count ?? ($this->relationLoaded('approvalSteps') ? $this->approvalSteps->count() : $this->approvalSteps()->count()),
            'rejection_reason' => $this->rejection_reason,
            // Older app versions read rejection_note.
            'rejection_note' => $this->rejection_reason,
            'attachment' => StorageUrl::for($this->attachment),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
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
}
