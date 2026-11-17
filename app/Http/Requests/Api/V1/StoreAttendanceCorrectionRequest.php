<?php

namespace App\Http\Requests\Api\V1;

use App\Models\AttendanceCorrection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreAttendanceCorrectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'date' => ['required', 'date', 'before_or_equal:today'],
            'corrected_check_in' => ['nullable', 'required_without:corrected_check_out', 'date_format:'.AttendanceCorrection::TIME_INPUT_FORMATS],
            'corrected_check_out' => ['nullable', 'required_without:corrected_check_in', 'date_format:'.AttendanceCorrection::TIME_INPUT_FORMATS],
            'reason' => ['required', 'string', 'max:1000'],
            'attachment' => ['nullable', 'file', 'mimes:jpeg,png,jpg,pdf,doc,docx', 'max:5120'],
        ];
    }

    /**
     * Indonesian messages for the corrected times and the attachment, matching the mobile form.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'date.before_or_equal' => AttendanceCorrection::FUTURE_DATE_MESSAGE,
            'corrected_check_in.required_without' => 'Isi jam masuk atau jam pulang yang benar.',
            'corrected_check_out.required_without' => 'Isi jam masuk atau jam pulang yang benar.',
            'corrected_check_in.date_format' => 'Jam masuk harus berformat JJ:MM, misalnya 08:00.',
            'corrected_check_out.date_format' => 'Jam pulang harus berformat JJ:MM, misalnya 17:00.',
            'attachment.uploaded' => 'Lampiran gagal terunggah. Periksa koneksi lalu coba lagi, ukuran maksimal 5 MB.',
            'attachment.file' => 'Lampiran harus berupa berkas.',
            'attachment.mimes' => 'Lampiran harus berformat JPG, PNG, PDF, atau Word.',
            'attachment.max' => 'Lampiran maksimal berukuran 5 MB.',
        ];
    }

    /**
     * Refuses, before the attachment is stored, a time that has not happened yet, an attendance still
     * waiting for or refused by the geofence approver, and a second correction for a worker, date and
     * segment that already has a pending or approved one: approving both would let the later silently
     * overwrite the earlier.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $user = $this->user();

                if (! $user || $validator->errors()->isNotEmpty()) {
                    return;
                }

                $draft = new AttendanceCorrection([
                    'employee_id' => $user->employee?->id,
                    'intern_id' => $user->employee ? null : $user->intern?->id,
                    'freelancer_id' => ($user->employee || $user->intern) ? null : $user->freelancer?->id,
                    'date' => $this->input('date'),
                    'corrected_check_in' => $this->input('corrected_check_in'),
                    'corrected_check_out' => $this->input('corrected_check_out'),
                ]);

                foreach ($draft->submissionErrors() as $field => $message) {
                    $validator->errors()->add($field, $message);
                }
            },
        ];
    }
}
