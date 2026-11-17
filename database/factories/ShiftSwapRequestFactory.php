<?php

namespace Database\Factories;

use App\Models\ShiftSwapRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Attach the workers and company in the test: a swap is only meaningful between two workers of one company.
 *
 * @extends Factory<ShiftSwapRequest>
 */
class ShiftSwapRequestFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => ShiftSwapRequest::TYPE_SWAP,
            'requester_date' => today()->addDays(2)->toDateString(),
            'counterpart_date' => today()->addDays(2)->toDateString(),
            'reason' => fake()->sentence(),
            'schedule_snapshot' => [],
            'status' => ShiftSwapRequest::STATUS_AWAITING_COLLEAGUE,
        ];
    }

    public function giveAway(): static
    {
        return $this->state(fn (): array => ['type' => ShiftSwapRequest::TYPE_GIVE_AWAY, 'counterpart_date' => null]);
    }

    public function pending(): static
    {
        return $this->state(fn (): array => ['status' => ShiftSwapRequest::STATUS_PENDING, 'current_step' => 1]);
    }
}
