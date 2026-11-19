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
            'corrected_check_in' => ['nullable', 'required_without_all:corrected_check_out,corrected_shift_id,corrected_company_location_id', 'date_format:'.AttendanceCorrection::TIME_INPUT_FORMATS],
            'corrected_check_out' => ['nullable', 'required_without_all:corrected_check_in,corrected_shift_id,corrected_company_location_id', 'date_format:'.AttendanceCorrection::TIME_INPUT_FORMATS],
            'reason' => ['required', 'string', 'max:1000'],
            'attachment' => ['nullable', 'file', 'mimes:jpeg,png,jpg,pdf,doc,docx', 'max:5120'],
            // Optional for newer apps: the attendance or schedule (and its part) corrected, a check-out on the
            // next day, and the shift and outlet that really applied (a schedule correction).
            ...self::targetRules(),
        ];
    }

    /**
     * The optional fields naming what a correction targets and the schedule it corrects.
     *
     * @return array<string, list<string>>
     */
    public static function targetRules(): array
    {
        return [
            'attendance_id' => ['nullable', 'integer', 'min:1'],
            'schedule_id' => ['nullable', 'integer', 'min:1'],
            'segment' => ['nullable', 'integer', 'in:1,2'],
            'check_out_next_day' => ['nullable', 'boolean'],
            'corrected_shift_id' => ['nullable', 'integer', 'min:1'],
            'corrected_company_location_id' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * Indonesian messages of the optional target fields.
     *
     * @return array<string, string>
     */
    public static function targetMessages(): array
    {
        return [
            'attendance_id.integer' => 'Absensi yang dipilih tidak valid.',
            'attendance_id.min' => 'Absensi yang dipilih tidak valid.',
            'schedule_id.integer' => 'Jadwal yang dipilih tidak valid.',
            'schedule_id.min' => 'Jadwal yang dipilih tidak valid.',
            'segment.integer' => 'Bagian shift yang dipilih tidak valid.',
            'segment.in' => 'Bagian shift yang dipilih tidak valid.',
            'check_out_next_day.boolean' => 'Pilihan jam pulang keesokan harinya tidak valid.',
            'corrected_shift_id.integer' => 'Shift yang dipilih tidak valid.',
            'corrected_shift_id.min' => 'Shift yang dipilih tidak valid.',
            'corrected_company_location_id.integer' => 'Outlet yang dipilih tidak valid.',
            'corrected_company_location_id.min' => 'Outlet yang dipilih tidak valid.',
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
            'corrected_check_in.required_without_all' => 'Isi jam masuk atau jam pulang yang benar.',
            'corrected_check_out.required_without_all' => 'Isi jam masuk atau jam pulang yang benar.',
            'corrected_check_in.date_format' => 'Jam masuk harus berformat JJ:MM, misalnya 08:00.',
            'corrected_check_out.date_format' => 'Jam pulang harus berformat JJ:MM, misalnya 17:00.',
            'attachment.uploaded' => 'Lampiran gagal terunggah. Periksa koneksi lalu coba lagi, ukuran maksimal 5 MB.',
            'attachment.file' => 'Lampiran harus berupa berkas.',
            'attachment.mimes' => 'Lampiran harus berformat JPG, PNG, PDF, atau Word.',
            'attachment.max' => 'Lampiran maksimal berukuran 5 MB.',
            ...self::targetMessages(),
        ];
    }

    /**
     * The checked draft, whose resolved target and corrected fields the controller saves.
     */
    private ?AttendanceCorrection $draft = null;

    /**
     * The fields the controller saves besides the times: the resolved target and the schedule corrected.
     *
     * @return array<string, mixed>
     */
    public function correctionAttributes(): array
    {
        return [
            ...($this->draft?->targetAttributes() ?? []),
            'check_out_next_day' => (bool) $this->draft?->check_out_next_day,
            'corrected_shift_id' => $this->draft?->corrected_shift_id,
            'corrected_company_location_id' => $this->draft?->corrected_company_location_id,
        ];
    }

    /**
     * Refuses, before the attachment is stored, a time that has not happened yet, a target that cannot be
     * told (several schedules on the date) or is not the worker's, a check-out without any check-in, an
     * attendance still waiting for the geofence approver, a check-out-only correction of a rejected one,
     * and a second correction for a worker, date and session that already has a pending or approved one:
     * approving both would let the later silently overwrite the earlier.
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
                    'check_out_next_day' => $this->boolean('check_out_next_day'),
                    'corrected_shift_id' => $this->filled('corrected_shift_id') ? (int) $this->input('corrected_shift_id') : null,
                    'corrected_company_location_id' => $this->filled('corrected_company_location_id') ? (int) $this->input('corrected_company_location_id') : null,
                ]);

                $errors = $draft->resolveTarget(
                    $this->filled('attendance_id') ? (int) $this->input('attendance_id') : null,
                    $this->filled('schedule_id') ? (int) $this->input('schedule_id') : null,
                    $this->filled('segment') ? (int) $this->input('segment') : null,
                );

                foreach ($errors ?: $draft->submissionErrors() as $field => $message) {
                    $validator->errors()->add($field, $message);
                }

                $this->draft = $draft;
            },
        ];
    }
}
