<?php

namespace App\Http\Requests\Api\V1;

use App\Services\ShiftAssignmentService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTeamScheduleRequest extends FormRequest
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
            'worker_type' => ['required', Rule::in(['employee', 'intern', 'freelancer'])],
            'worker_id' => ['required', 'integer'],
            'date' => ['required', 'date_format:Y-m-d'],
            'mode' => ['required', Rule::in([ShiftAssignmentService::MODE_SHIFT, ShiftAssignmentService::MODE_DAY_OFF, ShiftAssignmentService::MODE_CLEAR])],
            'shift_id' => ['required_if:mode,'.ShiftAssignmentService::MODE_SHIFT, 'nullable', 'integer'],
            'company_location_id' => ['nullable', 'integer'],
            'section' => ['nullable', 'string', 'max:50'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'shift_id.required_if' => 'Pilih shift.',
        ];
    }
}
