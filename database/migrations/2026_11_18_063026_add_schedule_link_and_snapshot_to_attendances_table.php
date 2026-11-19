<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An attendance row belongs to one schedule of its date (shift_schedule_id, schedule_sequence) and
     * keeps a snapshot of the schedule that applied when the worker checked in (sched_*), so a later
     * roster or shift change cannot alter its result. pending_punch, status_before_check_out and
     * check_out_rejected_at let an approver refuse a check-out without refusing the check-in.
     */
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->unsignedBigInteger('shift_schedule_id')->nullable()->after('shift_id');
            $table->index('shift_schedule_id', 'attendances_shift_schedule_id_index');
            $table->foreign('shift_schedule_id')->references('id')->on('shift_schedules')->nullOnDelete();
            $table->unsignedTinyInteger('schedule_sequence')->default(1)->after('date');
            $table->dateTime('sched_start_at')->nullable();
            $table->dateTime('sched_end_at')->nullable();
            $table->unsignedSmallInteger('sched_tolerance_minutes')->nullable();
            $table->unsignedSmallInteger('sched_scheduled_minutes')->nullable();
            $table->unsignedSmallInteger('sched_normal_minutes')->nullable();
            $table->string('sched_shift_name', 100)->nullable();
            $table->string('sched_shift_code', 30)->nullable();
            $table->string('sched_outlet_name', 150)->nullable();
            $table->string('sched_section', 100)->nullable();
            $table->string('sched_source', 20)->nullable();
            $table->string('pending_punch', 10)->nullable();
            $table->string('status_before_check_out', 30)->nullable();
            $table->dateTime('check_out_rejected_at')->nullable();
        });

        Schema::table('attendances', function (Blueprint $table) {
            $table->unique(['employee_id', 'date', 'schedule_sequence', 'segment'], 'attendances_employee_date_seq_segment_unique');
            $table->unique(['intern_id', 'date', 'schedule_sequence', 'segment'], 'attendances_intern_date_seq_segment_unique');
            $table->unique(['freelancer_id', 'date', 'schedule_sequence', 'segment'], 'attendances_freelancer_date_seq_segment_unique');
        });

        Schema::table('attendances', function (Blueprint $table) {
            $table->dropUnique('attendances_employee_date_segment_unique');
            $table->dropUnique('attendances_intern_date_segment_unique');
            $table->dropUnique('attendances_freelancer_date_segment_unique');
        });
    }

    /**
     * @throws RuntimeException when an attendance row already belongs to a second schedule of its date
     */
    public function down(): void
    {
        if (DB::table('attendances')->where('schedule_sequence', '>', 1)->exists()) {
            throw new RuntimeException('Tidak bisa dibatalkan: sudah ada absensi pada jadwal kedua di satu tanggal (attendances.schedule_sequence > 1). Hapus atau pindahkan absensi tersebut terlebih dahulu.');
        }

        Schema::table('attendances', function (Blueprint $table) {
            $table->unique(['employee_id', 'date', 'segment'], 'attendances_employee_date_segment_unique');
            $table->unique(['intern_id', 'date', 'segment'], 'attendances_intern_date_segment_unique');
            $table->unique(['freelancer_id', 'date', 'segment'], 'attendances_freelancer_date_segment_unique');
        });

        Schema::table('attendances', function (Blueprint $table) {
            $table->dropUnique('attendances_employee_date_seq_segment_unique');
            $table->dropUnique('attendances_intern_date_seq_segment_unique');
            $table->dropUnique('attendances_freelancer_date_seq_segment_unique');
            $table->dropForeign(['shift_schedule_id']);
        });

        Schema::table('attendances', function (Blueprint $table) {
            $table->dropIndex('attendances_shift_schedule_id_index');
        });

        Schema::table('attendances', function (Blueprint $table) {
            $table->dropColumn([
                'shift_schedule_id', 'schedule_sequence',
                'sched_start_at', 'sched_end_at', 'sched_tolerance_minutes', 'sched_scheduled_minutes', 'sched_normal_minutes',
                'sched_shift_name', 'sched_shift_code', 'sched_outlet_name', 'sched_section', 'sched_source',
                'pending_punch', 'status_before_check_out', 'check_out_rejected_at',
            ]);
        });
    }
};
