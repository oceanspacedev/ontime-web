<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class OtpVerifyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'challenge_id' => ['required', 'string', 'max:64'],
            'code' => ['required', 'string', 'regex:/^[0-9]{6}$/D'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.regex' => 'Kode OTP harus 6 digit angka.',
        ];
    }
}
