<?php

namespace App\Services\Attendance;

use App\Models\User;
use App\Services\AttendanceLedger;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * What an attendance report asks the ledger ({@see AttendanceLedger}): the business dates, the user it is
 * for (their data access decides what is shown) and optional filters.
 *
 * A query made with {@see self::forWorkers()} has no viewer: it reads exactly the named workers, for code
 * that already knows whose figures it may show (a worker's own dashboard, the netting of an overtime request).
 */
final readonly class LedgerQuery
{
    /**
     * @param  CarbonImmutable  $from  first business date (start of day)
     * @param  CarbonImmutable  $to  last business date (start of day)
     * @param  int|null  $companyId  only workers of this company
     * @param  int|null  $divisionId  only workers of this division
     * @param  int|null  $outletId  only lines attributed to this outlet
     * @param  string|null  $workerType  employee|intern|freelancer, together with $workerId: only this worker
     * @param  list<string>|null  $workerKeys  "type:id" keys; exactly these workers, whoever the viewer is
     */
    public function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public ?User $viewer,
        public ?int $companyId = null,
        public ?int $divisionId = null,
        public ?int $outletId = null,
        public ?string $workerType = null,
        public ?int $workerId = null,
        public ?array $workerKeys = null,
    ) {}

    /**
     * Exactly the given workers ("employee:5", "freelancer:9") on the business dates from $from up to and
     * including $to, without a viewer.
     *
     * @param  list<string>  $workerKeys
     */
    public static function forWorkers(array $workerKeys, CarbonInterface|string $from, CarbonInterface|string $to): self
    {
        return new self(
            CarbonImmutable::parse(is_string($from) ? $from : $from->format('Y-m-d'))->startOfDay(),
            CarbonImmutable::parse(is_string($to) ? $to : $to->format('Y-m-d'))->startOfDay(),
            null,
            workerKeys: array_values(array_unique($workerKeys)),
        );
    }

    /**
     * The whole calendar month of the given date.
     */
    public static function month(User $viewer, CarbonInterface $month): self
    {
        $start = CarbonImmutable::parse($month->format('Y-m-01'));

        return new self($start, $start->endOfMonth()->startOfDay(), $viewer);
    }

    /**
     * The business dates from $from up to and including $to.
     */
    public static function between(User $viewer, CarbonInterface|string $from, CarbonInterface|string $to, ?int $companyId = null): self
    {
        return new self(
            CarbonImmutable::parse(is_string($from) ? $from : $from->format('Y-m-d'))->startOfDay(),
            CarbonImmutable::parse(is_string($to) ? $to : $to->format('Y-m-d'))->startOfDay(),
            $viewer,
            $companyId,
        );
    }

    public function with(?int $companyId = null, ?int $divisionId = null, ?int $outletId = null, ?string $workerType = null, ?int $workerId = null): self
    {
        return new self(
            $this->from,
            $this->to,
            $this->viewer,
            $companyId ?? $this->companyId,
            $divisionId ?? $this->divisionId,
            $outletId ?? $this->outletId,
            $workerType ?? $this->workerType,
            $workerId ?? $this->workerId,
            $this->workerKeys,
        );
    }
}
