<?php

namespace App\Http\Requests\Api\V1;

use App\Models\DeviceToken;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegisterDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'max:255', 'regex:'.DeviceToken::TOKEN_PATTERN],
            'platform' => ['required', 'string', Rule::in(DeviceToken::PLATFORMS)],
        ];
    }

    public function messages(): array
    {
        return [
            'token.regex' => 'Token harus berupa Expo push token (ExponentPushToken[...]).',
        ];
    }
}
