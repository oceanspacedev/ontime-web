<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Services\WorkerAccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class EmployeeWebhookController extends Controller
{
    public function __construct(private WorkerAccountService $accounts) {}

    /**
     * Apply an employee change sent by the core (Talenta) system: "created"/"updated" sync the profile,
     * "deleted" removes an employee without history and only sets one with history inactive, so the
     * attendance, overtime, leave and resignation rows used for payroll are kept.
     */
    public function handle(Request $request): JsonResponse
    {
        $secret = (string) config('services.core.webhook_secret');
        $signature = (string) $request->header('X-Signature');

        // Without a secret every caller could sign with an empty key, so refuse instead.
        if ($secret === '') {
            Log::error('Employee webhook rejected: CORE_WEBHOOK_SECRET is not configured.');

            abort(503, 'Webhook is not configured.');
        }

        if ($signature === '' || ! hash_equals(hash_hmac('sha256', $request->getContent(), $secret), $signature)) {
            abort(401, 'Invalid signature.');
        }

        $payload = $request->validate([
            'action' => 'required|string',
            'employee' => 'required|array',
            'employee.id' => 'required|integer',
            'employee.name' => 'required|string',
            'employee.email' => 'nullable|string',
            'employee.phone' => 'nullable|string',
            'employee.status' => 'required|string',
        ]);

        $action = $payload['action'];
        $empData = $payload['employee'];

        Log::info("Received employee webhook in ontime: {$action} for {$empData['id']}");

        if ($action === 'created' || $action === 'updated') {
            $employee = Employee::find($empData['id']);
            if ($employee) {
                // Talent sends 'name', but Ontime has 'full_name'
                $employee->fill([
                    'full_name' => $empData['name'],
                    'email' => $empData['email'] ?? null,
                    'phone' => $empData['phone'] ?? null,
                    'status' => $empData['status'],
                ]);

                // WhatsApp OTP login finds the account by number: keep the old number rather than share one.
                if ($this->accounts->phoneConflicts($employee)) {
                    Log::warning("Employee webhook kept the old phone of employee {$employee->id}: the new number belongs to another active worker or account.");
                    $employee->phone = $employee->getRawOriginal('phone');
                }

                $employee->save();
            } else {
                // If it doesn't exist, we might need to create it with dummy required fields
                // For now, ontime schema has many required fields like company_id, so we only update.
                Log::warning("Webhook received for unknown employee ID {$empData['id']} in ontime.");
            }
        } elseif ($action === 'deleted') {
            $employee = Employee::find($empData['id']);

            if ($employee && $this->accounts->deletionBlockedReason($employee) !== null) {
                Log::info("Employee webhook set employee {$employee->id} inactive instead of deleting: it has payroll history.");
                $this->accounts->deactivateEmployee($employee);
            } else {
                $employee?->delete();
            }
        }

        return response()->json(['status' => 'success']);
    }
}
