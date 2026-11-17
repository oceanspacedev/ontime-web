<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An admin set the status or the late minutes by hand (Edit Presensi). A later roster change on that
     * date no longer judges the row again, so the manual verdict is not overwritten silently.
     */
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->boolean('status_overridden')->default(false)->after('is_corrected');
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropColumn('status_overridden');
        });
    }
};
