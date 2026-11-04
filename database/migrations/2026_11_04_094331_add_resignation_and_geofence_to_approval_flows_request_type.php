<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Default steps for request types that previously could not be stored.
     *
     * Resignation mirrors the panel's default 3-stage flow. Geofence mirrors the
     * single-step fallback that ApprovalFlowService used while no flow existed.
     *
     * @var array<string, list<array{name: string, approver_role: string}>>
     */
    private array $defaultSteps = [
        'resignation' => [
            ['name' => 'Persetujuan Approver Pengunduran Diri', 'approver_role' => 'Approver'],
            ['name' => 'Persetujuan BOD Pengunduran Diri', 'approver_role' => 'BOD'],
            ['name' => 'Persetujuan Akhir Superadmin Pengunduran Diri', 'approver_role' => 'Superadmin'],
        ],
        'geofence' => [
            ['name' => 'Persetujuan Presensi Luar Geofence', 'approver_role' => 'Approver'],
        ],
    ];

    public function up(): void
    {
        Schema::table('approval_flows', function (Blueprint $table) {
            $table->enum('request_type', ['leave', 'overtime', 'correction', 'resignation', 'geofence'])->change();
        });

        $now = now();

        foreach (DB::table('companies')->pluck('id') as $companyId) {
            foreach ($this->defaultSteps as $requestType => $steps) {
                $alreadyConfigured = DB::table('approval_flows')
                    ->where('company_id', $companyId)
                    ->where('request_type', $requestType)
                    ->exists();

                if ($alreadyConfigured) {
                    continue;
                }

                foreach ($steps as $index => $step) {
                    DB::table('approval_flows')->insert([
                        'company_id' => $companyId,
                        'request_type' => $requestType,
                        'step_order' => $index + 1,
                        'step_number' => $index + 1,
                        'name' => $step['name'],
                        'approver_type' => 'role',
                        'approver_role' => $step['approver_role'],
                        'user_id' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        DB::table('approval_flows')->whereIn('request_type', ['resignation', 'geofence'])->delete();

        Schema::table('approval_flows', function (Blueprint $table) {
            $table->enum('request_type', ['leave', 'overtime', 'correction'])->change();
        });
    }
};
