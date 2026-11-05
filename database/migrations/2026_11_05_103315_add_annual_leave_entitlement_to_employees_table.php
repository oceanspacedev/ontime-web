<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Annual leave is granted per employee, not to everyone. Nobody has it until HR turns it on, and
 * an empty quota means the company policy quota applies.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->boolean('has_annual_leave')->default(false)->after('join_date');
            $table->unsignedSmallInteger('annual_leave_quota')->nullable()->after('has_annual_leave');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn(['has_annual_leave', 'annual_leave_quota']);
        });
    }
};
