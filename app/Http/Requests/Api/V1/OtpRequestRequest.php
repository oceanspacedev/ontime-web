<?php

namespace App\Http\Requests\Api\V1;

use App\Services\ApiOtpLoginService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OtpRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'channel' => ['required', 'string', Rule::in(ApiOtpLoginService::CHANNELS)],
            'phone' => ['exclude_unless:channel,'.ApiOtpLoginService::CHANNEL_WHATSAPP, 'required', 'string', 'max:30'],
            'email' => ['exclude_unless:channel,'.ApiOtpLoginService::CHANNEL_EMAIL, 'required', 'string', 'email', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'channel.in' => 'Kanal OTP harus whatsapp atau email.',
            'phone.required' => 'Nomor HP wajib diisi.',
            'email.required' => 'Alamat email wajib diisi.',
        ];
    }

    public function destination(): string
    {
        return (string) ($this->validated('channel') === ApiOtpLoginService::CHANNEL_WHATSAPP
            ? $this->validated('phone')
            : $this->validated('email'));
    }
}
