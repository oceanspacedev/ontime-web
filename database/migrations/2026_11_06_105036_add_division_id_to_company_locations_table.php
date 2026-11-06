<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The brand (a division) an outlet belongs to, so shift codes in a schedule sheet resolve to that brand's shifts.
     */
    public function up(): void
    {
        Schema::table('company_locations', function (Blueprint $table) {
            $table->foreignId('division_id')->nullable()->after('company_id')->constrained('divisions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('company_locations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('division_id');
        });
    }
};
