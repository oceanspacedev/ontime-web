<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A cover check-in names the colleague's schedule row it replaces (D1: a colleague can have two rows on
     * the same shift at two outlets, each replaced by its own backup). Older covers have none and are
     * matched on the covered shift.
     */
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->unsignedBigInteger('replaced_schedule_id')->nullable()->after('replaced_worker_id');
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropColumn('replaced_schedule_id');
        });
    }
};
