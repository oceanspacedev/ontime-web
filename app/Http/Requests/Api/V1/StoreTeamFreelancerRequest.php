<?php

namespace App\Http\Requests\Api\V1;

use App\Services\WorkerAccountService;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;

class StoreTeamFreelancerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->canManageWorkers();
    }

    /**
     * Same Indonesian answer as the team's read endpoints, instead of Laravel's English default.
     *
     * @throws AuthorizationException
     */
    protected function failedAuthorization(): void
    {
        throw new AuthorizationException('Fitur tim hanya untuk koordinator.');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'full_name' => ['required', 'string', 'max:255'],
            'phone' => [
                'required',
                'string',
                'max:50',
                function (string $attribute, mixed $value, Closure $fail): void {
                    // "0812...", "62812..." and "+62 812..." are the same number.
                    if (app(WorkerAccountService::class)->phoneIsTaken((string) $value)) {
                        $fail(WorkerAccountService::PHONE_TAKEN_MESSAGE);
                    }
                },
            ],
            'company_location_id' => ['required', 'integer'],
            'start_date' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'full_name.required' => 'Nama wajib diisi.',
            'phone.required' => 'Nomor HP wajib diisi.',
            'company_location_id.required' => 'Pilih outlet.',
        ];
    }
}
