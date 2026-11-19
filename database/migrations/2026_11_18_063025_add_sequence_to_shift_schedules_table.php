<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A worker may have several schedules on one date (for example two outlets), numbered by sequence.
     * The date is unique per worker and sequence instead of per worker.
     */
    public function up(): void
    {
        Schema::table('shift_schedules', function (Blueprint $table) {
            $table->unsignedTinyInteger('sequence')->default(1)->after('date');
            $table->foreignId('updated_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
        });

        // The new indexes start with the worker column, so each worker foreign key keeps an index when the old ones go.
        Schema::table('shift_schedules', function (Blueprint $table) {
            $table->unique(['employee_id', 'date', 'sequence'], 'shift_schedules_employee_date_seq_unique');
            $table->unique(['intern_id', 'date', 'sequence'], 'shift_schedules_intern_date_seq_unique');
            $table->unique(['freelancer_id', 'date', 'sequence'], 'shift_schedules_freelancer_date_seq_unique');
        });

        Schema::table('shift_schedules', function (Blueprint $table) {
            $table->dropUnique(['employee_id', 'date']);
            $table->dropUnique(['intern_id', 'date']);
            $table->dropUnique(['freelancer_id', 'date']);
        });
    }

    /**
     * @throws RuntimeException when a worker already has a second schedule on a date
     */
    public function down(): void
    {
        if (DB::table('shift_schedules')->where('sequence', '>', 1)->exists()) {
            throw new RuntimeException('Tidak bisa dibatalkan: sudah ada pekerja dengan lebih dari satu jadwal pada satu tanggal (shift_schedules.sequence > 1). Hapus atau gabungkan jadwal tersebut terlebih dahulu.');
        }

        Schema::table('shift_schedules', function (Blueprint $table) {
            $table->unique(['employee_id', 'date']);
            $table->unique(['intern_id', 'date']);
            $table->unique(['freelancer_id', 'date']);
        });

        Schema::table('shift_schedules', function (Blueprint $table) {
            $table->dropUnique('shift_schedules_employee_date_seq_unique');
            $table->dropUnique('shift_schedules_intern_date_seq_unique');
            $table->dropUnique('shift_schedules_freelancer_date_seq_unique');
        });

        Schema::table('shift_schedules', function (Blueprint $table) {
            $table->dropConstrainedForeignId('updated_by');
            $table->dropColumn('sequence');
        });
    }
};
