<?php

namespace App\Http\Requests\Api\V1;

use App\Models\AttendanceCorrection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateAttendanceCorrectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'date' => ['sometimes', 'required', 'date', 'before_or_equal:today'],
            'corrected_check_in' => ['nullable', 'date_format:'.AttendanceCorrection::TIME_INPUT_FORMATS],
            'corrected_check_out' => ['nullable', 'date_format:'.AttendanceCorrection::TIME_INPUT_FORMATS],
            'reason' => ['sometimes', 'required', 'string', 'max:1000'],
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
            'corrected_check_in.date_format' => 'Jam masuk harus berformat JJ:MM, misalnya 08:00.',
            'corrected_check_out.date_format' => 'Jam pulang harus berformat JJ:MM, misalnya 17:00.',
            'attachment.uploaded' => 'Lampiran gagal terunggah. Periksa koneksi lalu coba lagi, ukuran maksimal 5 MB.',
            'attachment.file' => 'Lampiran harus berupa berkas.',
            'attachment.mimes' => 'Lampiran harus berformat JPG, PNG, PDF, atau Word.',
            'attachment.max' => 'Lampiran maksimal berukuran 5 MB.',
        ];
    }

    /**
     * A partial update may leave one time out, but the correction must keep at least one of them. The
     * changed correction is checked like a new one: no future date or time, no attendance pending or
     * refused by the geofence approver, no other pending or approved correction of the same date.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $correction = $this->ownCorrection();

                if (! $correction || $validator->errors()->hasAny(['corrected_check_in', 'corrected_check_out'])) {
                    return;
                }

                $checkIn = $this->exists('corrected_check_in') ? $this->input('corrected_check_in') : $correction->corrected_check_in;
                $checkOut = $this->exists('corrected_check_out') ? $this->input('corrected_check_out') : $correction->corrected_check_out;

                if (blank($checkIn) && blank($checkOut)) {
                    $validator->errors()->add('corrected_check_in', 'Isi jam masuk atau jam pulang yang benar.');

                    return;
                }

                if ($validator->errors()->has('date')) {
                    return;
                }

                $draft = $correction->replicate();
                $draft->id = $correction->id;
                $draft->exists = true;
                // Keys present in the request are taken as sent, an explicit null included: the
                // controller clears that time, so the draft must be checked without it too. Keys
                // left out keep the stored value, exactly as the controller saves them.
                $draft->fill($this->only(['date', 'corrected_check_in', 'corrected_check_out']));

                foreach ($draft->submissionErrors() as $field => $message) {
                    $validator->errors()->add($field, $message);
                }
            },
        ];
    }

    /**
     * Only the caller's own correction is checked, so an id that is not theirs falls through to the controller's 404.
     */
    private function ownCorrection(): ?AttendanceCorrection
    {
        $user = $this->user();
        $query = AttendanceCorrection::query();

        if ($user?->employee) {
            $query->where('employee_id', $user->employee->id);
        } elseif ($user?->intern) {
            $query->where('intern_id', $user->intern->id);
        } elseif ($user?->freelancer) {
            $query->where('freelancer_id', $user->freelancer->id);
        } else {
            return null;
        }

        return $query->find($this->route('id'));
    }
}
