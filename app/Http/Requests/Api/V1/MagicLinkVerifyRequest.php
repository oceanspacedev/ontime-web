<?php

namespace App\Http\Requests\Api\V1;

use App\Services\EmailOtpService;
use Illuminate\Foundation\Http\FormRequest;

class MagicLinkVerifyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'challenge_id' => ['required', 'string', 'max:64'],
            'token' => ['required', 'string', 'regex:/^[A-Za-z0-9]{'.EmailOtpService::TOKEN_LENGTH.'}$/D'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'token.regex' => 'Link masuk tidak valid.',
        ];
    }
}
