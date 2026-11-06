<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Franchise outlets: a shift belongs to a brand (a division), and a dated schedule names the outlet
     * (a company location) and the section the worker covers that day. Attendance keeps the outlet it counted for.
     */
    public function up(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->foreignId('division_id')->nullable()->after('company_id')->constrained('divisions')->nullOnDelete();
            // The new index also starts with company_id, so the company foreign key keeps an index when the old one goes.
            $table->unique(['company_id', 'division_id', 'name']);
        });

        Schema::table('shifts', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'name']);
        });

        Schema::table('shift_schedules', function (Blueprint $table) {
            $table->foreignId('company_location_id')->nullable()->after('shift_id')->constrained('company_locations')->nullOnDelete();
            $table->string('section', 50)->nullable()->after('is_day_off');
        });

        Schema::table('attendances', function (Blueprint $table) {
            $table->foreignId('company_location_id')->nullable()->after('shift_id')->constrained('company_locations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropConstrainedForeignId('company_location_id');
        });

        Schema::table('shift_schedules', function (Blueprint $table) {
            $table->dropConstrainedForeignId('company_location_id');
            $table->dropColumn('section');
        });

        Schema::table('shifts', function (Blueprint $table) {
            $table->unique(['company_id', 'name']);
        });

        Schema::table('shifts', function (Blueprint $table) {
            $table->dropForeign(['division_id']);
            $table->dropUnique(['company_id', 'division_id', 'name']);
            $table->dropColumn('division_id');
        });
    }
};
