<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Minutes between the check-out and the scheduled end of the shift (segment) when the worker
     * leaves early ("pulang cepat"); 0 when on time or later, null before the check-out.
     */
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->unsignedInteger('early_leave_minutes')->nullable()->after('check_in_late_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropColumn('early_leave_minutes');
        });
    }
};
