<?php

namespace App\Http\Resources\Api\V1;

use App\Support\StorageUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AttendanceCorrectionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $profile = $this->employee ?? $this->intern ?? $this->freelancer;

        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'intern_id' => $this->intern_id ?? null,
            'freelancer_id' => $this->freelancer_id ?? null,
            'date' => $this->date?->format('Y-m-d'),
            'corrected_check_in' => $this->corrected_check_in,
            'corrected_check_out' => $this->corrected_check_out,
            'reason' => $this->reason,
            'status' => $this->status,
            'current_step' => $this->current_step,
            'total_steps' => $this->approval_steps_count ?? ($this->relationLoaded('approvalSteps') ? $this->approvalSteps->count() : $this->approvalSteps()->count()),
            'rejection_reason' => $this->rejection_reason,
            // Older app versions read rejection_note.
            'rejection_note' => $this->rejection_reason,
            'attachment' => StorageUrl::for($this->attachment),
            // Additive (newer apps): the session corrected, a check-out on the next day, the shift and
            // outlet that really applied, and whether the record is a worker's request or an admin's edit.
            'attendance_id' => $this->attendance_id,
            'schedule_id' => $this->shift_schedule_id,
            'sequence' => $this->target_sequence,
            'segment' => $this->target_segment,
            'check_out_next_day' => (bool) $this->check_out_next_day,
            'corrected_shift_id' => $this->corrected_shift_id,
            'corrected_company_location_id' => $this->corrected_company_location_id,
            'origin' => $this->origin ?? 'worker',
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
