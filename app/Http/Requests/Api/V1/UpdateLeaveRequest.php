<?php

namespace App\Http\Requests\Api\V1;

use App\Models\LeaveRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'start_date' => ['sometimes', 'required', 'date'],
            'end_date' => ['sometimes', 'required', 'date', 'after_or_equal:start_date'],
            'reason' => ['sometimes', 'required', 'string', 'max:1000'],
            'attachment' => ['nullable', 'file', 'mimes:jpeg,png,jpg,pdf,doc,docx', 'max:5120'],
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
