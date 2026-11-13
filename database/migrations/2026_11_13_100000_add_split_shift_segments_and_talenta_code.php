<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Split shifts: a shift may have a second working segment (for example 07:45-13:00 and 17:45-22:00),
     * and an attendance row belongs to one segment, so a worker has up to two rows per day.
     * A shift also keeps the code the payroll system (Talenta) uses for it.
     */
    public function up(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->string('talenta_code', 50)->nullable()->after('code');
            $table->time('second_start_time')->nullable()->after('end_time');
            $table->time('second_end_time')->nullable()->after('second_start_time');
        });

        Schema::table('attendances', function (Blueprint $table) {
            $table->unsignedTinyInteger('segment')->default(1)->after('date');
            $table->unique(['employee_id', 'date', 'segment'], 'attendances_employee_date_segment_unique');
            $table->unique(['intern_id', 'date', 'segment'], 'attendances_intern_date_segment_unique');
            $table->unique(['freelancer_id', 'date', 'segment'], 'attendances_freelancer_date_segment_unique');
        });

        Schema::table('attendances', function (Blueprint $table) {
            $table->dropUnique('attendances_employee_date_unique');
            $table->dropUnique('attendances_intern_date_unique');
            $table->dropUnique('attendances_freelancer_date_unique');
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->unique(['employee_id', 'date'], 'attendances_employee_date_unique');
            $table->unique(['intern_id', 'date'], 'attendances_intern_date_unique');
            $table->unique(['freelancer_id', 'date'], 'attendances_freelancer_date_unique');
        });

        Schema::table('attendances', function (Blueprint $table) {
            $table->dropUnique('attendances_employee_date_segment_unique');
            $table->dropUnique('attendances_intern_date_segment_unique');
            $table->dropUnique('attendances_freelancer_date_segment_unique');
            $table->dropColumn('segment');
        });

        Schema::table('shifts', function (Blueprint $table) {
            $table->dropColumn(['talenta_code', 'second_start_time', 'second_end_time']);
        });
    }
};
