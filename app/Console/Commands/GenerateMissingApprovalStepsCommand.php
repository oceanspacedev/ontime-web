<?php

namespace App\Console\Commands;

use App\Models\Attendance;
use App\Models\AttendanceCorrection;
use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use App\Models\Resignation;
use App\Services\ApprovalFlowService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class GenerateMissingApprovalStepsCommand extends Command
{
    protected $signature = 'approvals:generate-missing-steps {--dry-run : List the stuck requests without changing anything}';

    protected $description = 'Create approval steps for pending requests that were saved without any, so they can be approved';

    /**
     * @var array<class-string<Model>, array{type: string, statuses: list<string>}>
     */
    private const REQUEST_TYPES = [
        LeaveRequest::class => ['type' => 'leave', 'statuses' => ['pending']],
        OvertimeRequest::class => ['type' => 'overtime', 'statuses' => ['pending']],
        AttendanceCorrection::class => ['type' => 'correction', 'statuses' => ['pending']],
        Resignation::class => ['type' => 'resignation', 'statuses' => ['pending']],
        Attendance::class => ['type' => 'geofence', 'statuses' => ['pending_approval']],
    ];

    public function handle(ApprovalFlowService $approvalFlowService): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $rows = [];

        foreach (self::REQUEST_TYPES as $modelClass => ['type' => $requestType, 'statuses' => $statuses]) {
            $stuckRequests = $modelClass::query()
                ->whereIn('status', $statuses)
                ->whereDoesntHave('approvalSteps')
                ->get();

            foreach ($stuckRequests as $request) {
                $result = 'akan dibuatkan tahap';

                if (! $dryRun) {
                    try {
                        $steps = $approvalFlowService->generateSteps($request, $requestType);
                        $result = count($steps).' tahap dibuat';
                    } catch (ValidationException $exception) {
                        $result = 'gagal: '.collect($exception->errors())->flatten()->first();
                    }
                }

                $rows[] = [$requestType, $request->getKey(), $result];
            }
        }

        if ($rows === []) {
            $this->info('Tidak ada pengajuan pending tanpa tahap approval.');

            return self::SUCCESS;
        }

        $this->table(['Jenis', 'ID', 'Hasil'], $rows);

        return self::SUCCESS;
    }
}
