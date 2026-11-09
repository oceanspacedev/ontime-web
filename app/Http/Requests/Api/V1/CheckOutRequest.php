<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class CheckOutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Multipart forms send booleans as text, so "true" and "false" are accepted next to 1 and 0.
     */
    protected function prepareForValidation(): void
    {
        $isMocked = $this->input('is_mocked');

        if (is_string($isMocked) && in_array(strtolower($isMocked), ['true', 'false'], true)) {
            $this->merge(['is_mocked' => strtolower($isMocked) === 'true']);
        }
    }

    public function rules(): array
    {
        return [
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'accuracy' => ['nullable', 'numeric', 'min:0'],
            'is_mocked' => ['nullable', 'boolean'],
            'photo' => ['nullable', 'image', 'mimes:jpeg,png,jpg,webp', 'max:5120'],
            'liveness_challenge_id' => ['nullable', 'string', 'max:64'],
            'liveness_frames' => ['nullable', 'array', 'max:3'],
            'liveness_frames.*' => ['image', 'mimes:jpeg,png,jpg,webp', 'max:5120'],
        ];
    }

    public function messages(): array
    {
        return [
            'photo.image' => 'Foto selfie harus berupa berkas gambar.',
            'photo.mimes' => 'Foto selfie harus berformat jpeg, png, jpg, atau webp.',
            'photo.max' => 'Foto selfie maksimal berukuran 5MB.',
            'liveness_frames.max' => 'Jumlah foto liveness tidak sesuai. Mulai ulang verifikasi.',
            'liveness_frames.*.image' => 'Foto liveness harus berupa berkas gambar.',
            'liveness_frames.*.mimes' => 'Foto liveness harus berformat jpeg, png, jpg, atau webp.',
            'liveness_frames.*.max' => 'Setiap foto liveness maksimal berukuran 5MB.',
        ];
    }
}
