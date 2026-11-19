<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * uses_shift_roster: in this company a day without a schedule means "not scheduled" instead of the
     * default working hours. It stays off until the owner switches it on per company.
     * overtime_netting_from: first date from which overtime requests are netted against the shift hours;
     * null keeps counting every request in full.
     */
    public function up(): void
    {
        Schema::table('company_policies', function (Blueprint $table) {
            $table->boolean('uses_shift_roster')->default(false);
            $table->date('overtime_netting_from')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('company_policies', function (Blueprint $table) {
            $table->dropColumn(['uses_shift_roster', 'overtime_netting_from']);
        });
    }
};
