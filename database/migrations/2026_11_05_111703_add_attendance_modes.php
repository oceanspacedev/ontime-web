<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('company_policies', function (Blueprint $table) {
            $table->boolean('require_liveness')->default(false)->after('face_fail_action');
            $table->string('mock_location_action', 20)->default('reject')->after('require_liveness');
        });

        Schema::table('attendances', function (Blueprint $table) {
            $table->boolean('is_mock_location')->default(false)->after('is_out_of_bounds');
            $table->boolean('liveness_verified')->nullable()->after('face_verification_notes');
            $table->string('liveness_notes')->nullable()->after('liveness_verified');
            $table->json('liveness_frames')->nullable()->after('liveness_notes');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('company_policies', function (Blueprint $table) {
            $table->dropColumn(['require_liveness', 'mock_location_action']);
        });

        Schema::table('attendances', function (Blueprint $table) {
            $table->dropColumn(['is_mock_location', 'liveness_verified', 'liveness_notes', 'liveness_frames']);
        });
    }
};
