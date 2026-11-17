<?php

namespace App\Http\Requests\Api\V1;

use App\Models\LeaveRequest;
use App\Services\AnnualLeaveService;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateLeaveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Accept the older mobile codes (annual, permitted) as the shared leave types.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('leave_type')) {
            $this->merge(['leave_type' => LeaveRequest::normalizeType($this->input('leave_type'))]);
        }
    }

    public function rules(): array
    {
        return [
            'leave_type' => ['sometimes', 'required', 'string', Rule::in(array_keys(LeaveRequest::TYPES))],
            'start_date' => ['bail', 'sometimes', 'required', 'string', 'date'],
            // Compared only with a string start date: the rule parses the other field, and an array crashes it.
            'end_date' => ['bail', 'sometimes', 'required', 'string', 'date', Rule::when(is_string($this->input('start_date')), 'after_or_equal:start_date')],
            'reason' => ['sometimes', 'required', 'string', 'max:1000'],
            'attachment' => ['nullable', 'file', 'mimes:jpeg,png,jpg,pdf,doc,docx', 'max:5120'],
        ];
    }

    /**
     * New dates must lie within the limits of LeaveRequest::periodErrors() and may not overlap another
     * pending or approved leave of the worker. New dates or a new type of annual leave are checked
     * against the quota of each calendar year the days fall in. A
     * request the worker does not own is left to the controller (404).
     *
     * @return list<callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty() || ! $this->hasAny(['leave_type', 'start_date', 'end_date'])) {
                    return;
                }

                $user = $this->user();
                $ownerColumn = match (true) {
                    $user?->employee !== null => ['employee_id', $user->employee->id],
                    $user?->intern !== null => ['intern_id', $user->intern->id],
                    $user?->freelancer !== null => ['freelancer_id', $user->freelancer->id],
                    default => null,
                };

                $leave = $ownerColumn
                    ? LeaveRequest::query()->where($ownerColumn[0], $ownerColumn[1])->find($this->route('id'))
                    : null;

                if (! $leave || $leave->status !== 'pending') {
                    return;
                }

                $startDate = Carbon::parse($this->input('start_date', $leave->start_date));
                $endDate = Carbon::parse($this->input('end_date', $leave->end_date));

                if ($endDate->lessThan($startDate)) {
                    return;
                }

                $daysCount = (int) $startDate->diffInDays($endDate) + 1;
                $datesChanged = $startDate->toDateString() !== $leave->start_date->toDateString()
                    || $endDate->toDateString() !== $leave->end_date->toDateString();

                if ($datesChanged) {
                    foreach (LeaveRequest::periodErrors($startDate, $endDate) as $field => $message) {
                        $validator->errors()->add($field, $message);

                        return;
                    }
                }

                $leaveService = app(AnnualLeaveService::class);
                $profile = $leave->getWorkerProfile();

                if ($error = $leaveService->updateError($leave, $this->input('leave_type'), $daysCount, $startDate, $endDate)
                    ?? ($datesChanged && $profile ? $leaveService->overlapError($profile, $startDate, $endDate, $leave->getKey()) : null)) {
                    $validator->errors()->add('leave_type', $error);
                }
            },
        ];
    }

    /**
     * Indonesian messages for the attachment, matching the hint shown in the mobile form.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'attachment.uploaded' => 'Lampiran gagal terunggah. Periksa koneksi lalu coba lagi, ukuran maksimal 5 MB.',
            'attachment.file' => 'Lampiran harus berupa berkas.',
            'attachment.mimes' => 'Lampiran harus berformat JPG, PNG, PDF, atau Word.',
            'attachment.max' => 'Lampiran maksimal berukuran 5 MB.',
        ];
    }
}
