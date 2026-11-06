<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LivenessChallengeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'purpose' => ['required', 'string', Rule::in(['check_in', 'check_out'])],
        ];
    }

    public function messages(): array
    {
        return [
            'purpose.required' => 'Tujuan verifikasi liveness wajib diisi.',
            'purpose.in' => 'Tujuan verifikasi liveness harus check_in atau check_out.',
        ];
    }
}
