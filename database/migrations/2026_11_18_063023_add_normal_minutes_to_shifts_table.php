<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Normal working minutes of the whole shift (both parts of a split shift together). Time worked
     * inside the shift above it is automatic overtime. Null means the whole shift is normal time.
     */
    public function up(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->unsignedSmallInteger('normal_minutes')->nullable()->after('second_end_time');
        });
    }

    public function down(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->dropColumn('normal_minutes');
        });
    }
};
