<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class UpdateOvertimeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * An end time before the start time means the overtime runs past midnight. A time sent alone is
     * checked against the stored other time in the controller (equal times and more than 12 hours are refused).
     * The date window, the contract, the duration and overlaps are checked in the controller, and only for
     * a date or time that actually changes, so the reason of an older request stays editable.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'date' => ['sometimes', 'required', 'date'],
            'start_time' => ['sometimes', 'required', 'date_format:H:i'],
            'end_time' => ['sometimes', 'required', 'date_format:H:i', 'different:start_time'],
            'reason' => ['sometimes', 'required', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'end_time.different' => 'Jam selesai lembur tidak boleh sama dengan jam mulai.',
        ];
    }
}
