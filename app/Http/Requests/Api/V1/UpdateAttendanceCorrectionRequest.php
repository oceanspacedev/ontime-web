<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAttendanceCorrectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'date' => ['sometimes', 'required', 'date'],
            'corrected_check_in' => ['nullable', 'string'],
            'corrected_check_out' => ['nullable', 'string'],
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
