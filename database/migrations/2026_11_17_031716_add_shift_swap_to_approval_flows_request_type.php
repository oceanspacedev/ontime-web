<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Allow the shift_swap request type and give every existing company its default single-stage flow
     * (the same Approver stage createDefaultFlows() gives a new company). Companies that already have a
     * shift_swap flow keep it.
     */
    public function up(): void
    {
        Schema::table('approval_flows', function (Blueprint $table) {
            $table->enum('request_type', ['leave', 'overtime', 'correction', 'resignation', 'geofence', 'shift_swap'])->change();
        });

        $now = now();

        foreach (DB::table('companies')->pluck('id') as $companyId) {
            $alreadyConfigured = DB::table('approval_flows')
                ->where('company_id', $companyId)
                ->whereNull('division_id')
                ->where('request_type', 'shift_swap')
                ->exists();

            if ($alreadyConfigured) {
                continue;
            }

            DB::table('approval_flows')->insert([
                'company_id' => $companyId,
                'request_type' => 'shift_swap',
                'step_order' => 1,
                'step_number' => 1,
                'name' => 'Persetujuan Tukar Shift',
                'approver_type' => 'role',
                'approver_role' => 'Approver',
                'user_id' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('approval_flows')->where('request_type', 'shift_swap')->delete();

        Schema::table('approval_flows', function (Blueprint $table) {
            $table->enum('request_type', ['leave', 'overtime', 'correction', 'resignation', 'geofence'])->change();
        });
    }
};
