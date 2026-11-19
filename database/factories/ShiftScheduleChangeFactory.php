<?php

namespace Database\Factories;

use App\Models\ShiftScheduleChange;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Attach the worker (and the schedule, when it still exists) in the test.
 *
 * @extends Factory<ShiftScheduleChange>
 */
class ShiftScheduleChangeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'date' => today()->toDateString(),
            'sequence' => 1,
            'action' => ShiftScheduleChange::ACTION_CREATED,
            'source' => 'panel',
            'before' => null,
            'after' => ['is_day_off' => true, 'shift_id' => null, 'shift_code' => null, 'company_location_id' => null, 'outlet_name' => null, 'section' => null, 'sequence' => 1],
        ];
    }

    public function deleted(): static
    {
        return $this->state(fn (array $attributes): array => [
            'action' => ShiftScheduleChange::ACTION_DELETED,
            'before' => $attributes['after'],
            'after' => null,
        ]);
    }
}
