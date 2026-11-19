<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A correction names the schedule and part it targets, may place the check-out on the next day,
     * may correct the shift or outlet of the day, and keeps the values it replaced. origin tells a
     * worker's request from the record of an admin edit.
     */
    public function up(): void
    {
        Schema::table('attendance_corrections', function (Blueprint $table) {
            $table->unsignedBigInteger('shift_schedule_id')->nullable()->index();
            $table->unsignedTinyInteger('target_sequence')->nullable();
            $table->unsignedTinyInteger('target_segment')->nullable();
            $table->boolean('check_out_next_day')->default(false);
            $table->foreignId('corrected_shift_id')->nullable()->constrained('shifts')->nullOnDelete();
            $table->foreignId('corrected_company_location_id')->nullable()->constrained('company_locations')->nullOnDelete();
            $table->string('origin', 10)->default('worker');
            $table->json('before_values')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('attendance_corrections', function (Blueprint $table) {
            $table->dropIndex(['shift_schedule_id']);
            $table->dropConstrainedForeignId('corrected_shift_id');
            $table->dropConstrainedForeignId('corrected_company_location_id');
        });

        Schema::table('attendance_corrections', function (Blueprint $table) {
            $table->dropColumn(['shift_schedule_id', 'target_sequence', 'target_segment', 'check_out_next_day', 'origin', 'before_values']);
        });
    }
};
