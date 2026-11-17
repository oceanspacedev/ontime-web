<?php

namespace App\Http\Requests\Api\V1;

use App\Models\ShiftSwapRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreShiftSwapRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * A swap names the colleague's date (the same date for a same-day swap); a give away only names the
     * requester's date. Schedule, company and date rules are checked by ShiftSwapService.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in([ShiftSwapRequest::TYPE_SWAP, ShiftSwapRequest::TYPE_GIVE_AWAY])],
            'requester_date' => ['required', 'date_format:Y-m-d'],
            'counterpart_date' => ['nullable', 'date_format:Y-m-d'],
            'colleague_type' => ['required', Rule::in(['employee', 'intern', 'freelancer'])],
            'colleague_id' => ['required', 'integer', 'min:1'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'type.required' => 'Pilih jenis tukar shift.',
            'type.in' => 'Jenis tukar shift tidak valid.',
            'requester_date.required' => 'Pilih tanggal shift Anda.',
            'requester_date.date_format' => 'Format tanggal shift Anda tidak valid.',
            'counterpart_date.date_format' => 'Format tanggal shift rekan tidak valid.',
            'colleague_type.required' => 'Pilih rekan kerja.',
            'colleague_type.in' => 'Rekan kerja tidak valid.',
            'colleague_id.required' => 'Pilih rekan kerja.',
            'reason.max' => 'Alasan maksimal 1000 karakter.',
        ];
    }
}
