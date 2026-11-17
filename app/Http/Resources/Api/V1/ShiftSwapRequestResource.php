<?php

namespace App\Http\Resources\Api\V1;

use App\Models\CompanyLocation;
use App\Models\Employee;
use App\Models\Freelancer;
use App\Models\Intern;
use App\Models\Shift;
use App\Models\ShiftSwapRequest;
use App\Services\ShiftSwapService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\AbstractPaginator;
use Illuminate\Support\Collection;
use WeakMap;

/**
 * @mixin ShiftSwapRequest
 */
class ShiftSwapRequestResource extends JsonResource
{
    /**
     * Shifts and outlet names named by the snapshots of a whole list, read once by collection() so days() does
     * not query per item. Keyed by the request model, and gone with it.
     *
     * @var WeakMap<ShiftSwapRequest, array{shifts: Collection<int, Shift>, locations: Collection<int, string>}>|null
     */
    private static ?WeakMap $snapshotLookups = null;

    /**
     * Preload what days() reads for every request of the list (a page or a plain collection).
     *
     * @param  mixed  $resource
     */
    public static function collection($resource): AnonymousResourceCollection
    {
        $swaps = collect($resource instanceof AbstractPaginator ? $resource->items() : $resource)
            ->filter(fn ($swap): bool => $swap instanceof ShiftSwapRequest);

        if ($swaps->isNotEmpty()) {
            $cells = $swaps->flatMap(fn (ShiftSwapRequest $swap): array => array_values($swap->schedule_snapshot ?? []));
            $lookup = self::lookupsFor($cells);
            self::$snapshotLookups ??= new WeakMap;

            foreach ($swaps as $swap) {
                self::$snapshotLookups[$swap] = $lookup;
            }
        }

        return parent::collection($resource);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $cells
     * @return array{shifts: Collection<int, Shift>, locations: Collection<int, string>}
     */
    private static function lookupsFor(Collection $cells): array
    {
        $shiftIds = $cells->pluck('shift_id')->filter()->unique()->values();
        $locationIds = $cells->pluck('company_location_id')->filter()->unique()->values();

        return [
            'shifts' => $shiftIds->isEmpty() ? collect() : Shift::query()->whereIn('id', $shiftIds)->get()->keyBy('id'),
            'locations' => $locationIds->isEmpty() ? collect() : CompanyLocation::query()->whereIn('id', $locationIds)->pluck('name', 'id'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $requester = $this->getWorkerProfile();
        $counterpart = $this->counterpartProfile();
        $user = $request->user();
        $status = $this->resource->effectiveStatus();
        $isOpen = in_array($status, ShiftSwapRequest::OPEN_STATUSES, true);

        return [
            'id' => $this->id,
            'type' => $this->type,
            'requester_date' => $this->requester_date?->format('Y-m-d'),
            'counterpart_date' => $this->counterpart_date?->format('Y-m-d'),
            'reason' => $this->reason,
            'status' => $status,
            'status_label' => $this->statusLabel(),
            'current_step' => $this->current_step,
            'rejection_reason' => $this->rejection_reason,
            'failure_reason' => $this->failure_reason,
            'counterpart_responded_at' => $this->counterpart_responded_at?->toISOString(),
            'applied_at' => $this->applied_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'requester' => $requester ? $this->formatWorker($requester) : null,
            'counterpart' => $counterpart ? $this->formatWorker($counterpart) : null,
            'employee_name' => $requester?->full_name,
            'days' => $this->days(),
            'is_mine' => $user ? $this->isRequestedBy($user) : false,
            'is_for_me' => $user ? $this->isCounterpart($user) : false,
            'can_respond' => $user !== null && $status === ShiftSwapRequest::STATUS_AWAITING_COLLEAGUE && $this->isCounterpart($user),
            'can_cancel' => $user !== null && $isOpen && $this->isRequestedBy($user),
            'approval_steps' => $this->relationLoaded('approvalSteps')
                ? $this->approvalSteps->sortBy('step_order')->values()->map(fn ($step): array => [
                    'step_order' => $step->step_order,
                    'step_name' => $step->step_name,
                    'status' => $step->status,
                    'notes' => $step->notes,
                    'actioned_at' => $step->actioned_at?->toISOString(),
                ])->all()
                : [],
        ];
    }

    /**
     * Per affected date, what each worker had when the request was made and what they get after the swap.
     *
     * @return list<array{date: string, requester_before: string, requester_after: string, counterpart_before: string, counterpart_after: string}>
     */
    private function days(): array
    {
        $snapshot = $this->schedule_snapshot ?? [];
        ['shifts' => $shifts, 'locations' => $locations] = self::$snapshotLookups[$this->resource] ?? self::lookupsFor(collect(array_values($snapshot)));

        $describe = function (?array $cell) use ($shifts, $locations): string {
            if (! $cell) {
                return '-';
            }

            if ($cell['off']) {
                return 'Libur';
            }

            $text = $shifts->get($cell['shift_id'])?->label() ?? 'Shift';

            if ($location = $locations->get($cell['company_location_id'])) {
                $text .= " · {$location}";
            }

            return filled($cell['section']) ? "{$text} · {$cell['section']}" : $text;
        };

        return collect($this->affectedDates())->map(fn (string $date): array => [
            'date' => $date,
            'requester_before' => $describe($snapshot["requester|{$date}"] ?? null),
            'requester_after' => $describe($snapshot["counterpart|{$date}"] ?? null),
            'counterpart_before' => $describe($snapshot["counterpart|{$date}"] ?? null),
            'counterpart_after' => $describe($snapshot["requester|{$date}"] ?? null),
        ])->all();
    }

    /**
     * @return array{type: string, id: int, full_name: string, division: string|null}
     */
    private function formatWorker(Employee|Intern|Freelancer $worker): array
    {
        return [
            'type' => ShiftSwapService::workerType($worker),
            'id' => (int) $worker->id,
            'full_name' => $worker->full_name ?? 'Pengguna',
            'division' => $worker->division?->name,
        ];
    }
}
