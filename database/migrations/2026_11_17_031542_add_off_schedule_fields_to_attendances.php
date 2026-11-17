<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A check-in outside the worker's schedule ("Masuk di luar jadwal"): on a scheduled day off, or a
     * freelancer without any schedule. It waits for approval and may name the colleague it covers for.
     */
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->boolean('is_off_schedule')->default(false)->after('early_leave_minutes');
            $table->string('replaced_worker_type', 20)->nullable()->after('is_off_schedule');
            $table->unsignedBigInteger('replaced_worker_id')->nullable()->after('replaced_worker_type');
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropColumn(['is_off_schedule', 'replaced_worker_type', 'replaced_worker_id']);
        });
    }
};
