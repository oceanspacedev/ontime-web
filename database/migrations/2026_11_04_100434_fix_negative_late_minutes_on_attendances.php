<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Check-ins were stored with a negative late_minutes because Carbon 3 returns a signed
     * difference. The magnitude is correct (minutes after the shift start), only the sign is wrong.
     */
    public function up(): void
    {
        DB::table('attendances')
            ->where('late_minutes', '<', 0)
            ->update(['late_minutes' => DB::raw('-late_minutes')]);
    }

    public function down(): void
    {
        // The original sign was a bug; there is nothing meaningful to restore.
    }
};
