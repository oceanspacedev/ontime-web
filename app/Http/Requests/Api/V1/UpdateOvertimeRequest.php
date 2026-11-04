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
     * An end time before the start time means the overtime runs past midnight.
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
}
