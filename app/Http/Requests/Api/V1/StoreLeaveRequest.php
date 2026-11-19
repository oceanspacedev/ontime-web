<?php

namespace App\Http\Requests\Api\V1;

use App\Models\LeaveRequest;
use App\Services\AnnualLeaveService;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreLeaveRequest extends FormRequest
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
            'leave_type' => ['required', 'string', Rule::in(array_keys(LeaveRequest::TYPES))],
            'start_date' => ['bail', 'required', 'string', 'date'],
            // Compared only with a string start date: the rule parses the other field, and an array crashes it.
            'end_date' => ['bail', 'required', 'string', 'date', Rule::when(is_string($this->input('start_date')), 'after_or_equal:start_date')],
            'reason' => ['required', 'string', 'max:1000'],
            'attachment' => ['nullable', 'file', 'mimes:jpeg,png,jpg,pdf,doc,docx', 'max:5120'],
        ];
    }

    /**
     * The period must lie within the limits of LeaveRequest::periodErrors(), and may not overlap another
     * pending or approved leave of the worker (so a request resent after a lost answer is not filed
     * twice). Annual leave is also checked against the quota of each calendar year its days fall in.
     *
     * @return list<callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $user = $this->user();
                $profile = $user?->employee ?? $user?->intern ?? $user?->freelancer;

                if ($validator->errors()->isNotEmpty() || ! $profile) {
                    return;
                }

                $startDate = Carbon::parse($this->input('start_date'));
                $endDate = Carbon::parse($this->input('end_date'));
                foreach (LeaveRequest::periodErrors($startDate, $endDate) as $field => $message) {
                    $validator->errors()->add($field, $message);

                    return;
                }

                $leaveService = app(AnnualLeaveService::class);
                $daysCount = count($leaveService->chargedDates($profile, $startDate, $endDate));

                if ($daysCount === 0) {
                    $validator->errors()->add('start_date', AnnualLeaveService::NO_WORKDAY_MESSAGE.'.');

                    return;
                }

                if ($error = $leaveService->validationError($profile, $this->input('leave_type'), $daysCount, startDate: $startDate, endDate: $endDate)
                    ?? $leaveService->overlapError($profile, $startDate, $endDate)) {
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
