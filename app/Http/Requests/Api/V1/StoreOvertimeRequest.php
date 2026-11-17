<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Employee;
use App\Models\Freelancer;
use App\Models\Intern;
use App\Services\OvertimeRequestService;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class StoreOvertimeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * An end time before the start time means the overtime runs past midnight. The duration is always
     * derived from the two times (at most 12 hours); a duration sent by the client is ignored.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'date' => ['required', 'date', $this->withinDateWindow(...), $this->withinContract(...)],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'different:start_time', $this->withinMaximumDuration(...)],
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'end_time.different' => 'Jam selesai lembur tidak boleh sama dengan jam mulai.',
        ];
    }

    /**
     * Overtime may be filed for today, up to 31 days back or up to 31 days ahead.
     */
    private function withinDateWindow(string $attribute, mixed $value, Closure $fail): void
    {
        $error = OvertimeRequestService::dateWindowError($value);

        if ($error !== null) {
            $fail($error);
        }
    }

    private function withinMaximumDuration(string $attribute, mixed $value, Closure $fail): void
    {
        $minutes = OvertimeRequestService::durationMinutes($this->input('start_time'), $value);

        if ($minutes !== null && $minutes > OvertimeRequestService::MAX_DURATION_MINUTES) {
            $fail('Durasi lembur maksimal 12 jam.');
        }
    }

    /**
     * Overtime only falls inside the worker's contract, see {@see OvertimeRequestService::contractError()}.
     */
    private function withinContract(string $attribute, mixed $value, Closure $fail): void
    {
        $user = $this->user();
        $profile = $user?->employee ?? $user?->intern ?? $user?->freelancer;

        if (! $profile instanceof Employee && ! $profile instanceof Intern && ! $profile instanceof Freelancer) {
            return;
        }

        $error = OvertimeRequestService::contractError($profile, $value, addressesWorker: true);

        if ($error !== null) {
            $fail($error);
        }
    }
}
