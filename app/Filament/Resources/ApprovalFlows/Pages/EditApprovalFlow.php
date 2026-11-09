<?php

namespace App\Filament\Resources\ApprovalFlows\Pages;

use App\Filament\Resources\ApprovalFlows\ApprovalFlowResource;
use App\Models\ApprovalFlow;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EditApprovalFlow extends EditRecord
{
    protected static string $resource = ApprovalFlowResource::class;

    public function getTitle(): string
    {
        return 'Alur Persetujuan '.$this->getRecord()->name;
    }

    public function getBreadcrumb(): string
    {
        return 'Atur';
    }

    protected function getCancelFormAction(): Action
    {
        return parent::getCancelFormAction()->label('Batal');
    }

    protected function getSaveFormAction(): Action
    {
        return parent::getSaveFormAction()->label('Simpan');
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $companyId = $this->record->id;

        $data['company_id'] = $companyId;

        $data['leave_steps'] = ApprovalFlow::companyWide()->where('company_id', $companyId)
            ->where('request_type', 'leave')
            ->orderBy('step_order')
            ->get()
            ->toArray();

        $data['overtime_steps'] = ApprovalFlow::companyWide()->where('company_id', $companyId)
            ->where('request_type', 'overtime')
            ->orderBy('step_order')
            ->get()
            ->toArray();

        $data['correction_steps'] = ApprovalFlow::companyWide()->where('company_id', $companyId)
            ->where('request_type', 'correction')
            ->orderBy('step_order')
            ->get()
            ->toArray();

        $data['resignation_steps'] = ApprovalFlow::companyWide()->where('company_id', $companyId)
            ->where('request_type', 'resignation')
            ->orderBy('step_order')
            ->get()
            ->toArray();

        $data['geofence_steps'] = ApprovalFlow::companyWide()->where('company_id', $companyId)
            ->where('request_type', 'geofence')
            ->orderBy('step_order')
            ->get()
            ->toArray();

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $companyId = $record->id;

        $leaveSteps = $data['leave_steps'] ?? [];
        $overtimeSteps = $data['overtime_steps'] ?? [];
        $correctionSteps = $data['correction_steps'] ?? [];
        $resignationSteps = $data['resignation_steps'] ?? [];
        $geofenceSteps = $data['geofence_steps'] ?? [];

        $this->validateSteps($leaveSteps, 'Cuti / Izin');
        $this->validateSteps($overtimeSteps, 'Lembur');
        $this->validateSteps($correctionSteps, 'Koreksi Absensi');
        $this->validateSteps($resignationSteps, 'Pengunduran Diri');
        $this->validateSteps($geofenceSteps, 'Absensi Luar Geofence');

        DB::transaction(function () use ($companyId, $leaveSteps, $overtimeSteps, $correctionSteps, $resignationSteps, $geofenceSteps) {
            ApprovalFlow::companyWide()->where('company_id', $companyId)->delete();

            $this->insertSteps($companyId, 'leave', $leaveSteps);
            $this->insertSteps($companyId, 'overtime', $overtimeSteps);
            $this->insertSteps($companyId, 'correction', $correctionSteps);
            $this->insertSteps($companyId, 'resignation', $resignationSteps);
            $this->insertSteps($companyId, 'geofence', $geofenceSteps);
        });

        return $record;
    }

    private function validateSteps(array $steps, string $label): void
    {
        if (count($steps) < 1) {
            throw ValidationException::withMessages([
                'steps' => "Alur {$label} wajib memiliki minimal 1 tahap approval.",
            ]);
        }

        foreach ($steps as $index => $step) {
            $approverType = $step['approver_type'] ?? 'role';
            if ($approverType === 'role' && empty($step['approver_role'])) {
                throw ValidationException::withMessages([
                    'steps' => 'Tahap '.($index + 1)." pada {$label} wajib memilih Role Approver.",
                ]);
            }
            if ($approverType === 'user' && empty($step['user_id'])) {
                throw ValidationException::withMessages([
                    'steps' => 'Tahap '.($index + 1)." pada {$label} wajib memilih User Spesifik.",
                ]);
            }
        }
    }

    private function insertSteps(int $companyId, string $requestType, array $steps): void
    {
        usort($steps, fn (array $first, array $second): int => ((int) ($first['step_order'] ?? 0)) <=> ((int) ($second['step_order'] ?? 0)));

        foreach (array_values($steps) as $index => $step) {
            $stepOrder = $index + 1;

            ApprovalFlow::create([
                'company_id' => $companyId,
                'request_type' => $requestType,
                'step_number' => $stepOrder,
                'step_order' => $stepOrder,
                'name' => ! empty($step['name']) ? $step['name'] : "Tahap {$stepOrder}",
                'approver_type' => $step['approver_type'] ?? 'role',
                'approver_role' => ($step['approver_type'] ?? 'role') === 'role' ? ($step['approver_role'] ?? 'Approver') : null,
                'user_id' => ($step['approver_type'] ?? 'role') === 'user' ? ($step['user_id'] ?? null) : null,
            ]);
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
