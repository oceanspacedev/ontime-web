<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The lateness judged at check-in against the schedule of that moment. A check-in waiting for
     * approval keeps late_minutes at 0 (reports must not count it as late yet), and the approval
     * then applies this value instead of judging against a roster or shift edited in the meantime.
     */
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->unsignedInteger('check_in_late_minutes')->nullable()->after('late_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropColumn('check_in_late_minutes');
        });
    }
};
