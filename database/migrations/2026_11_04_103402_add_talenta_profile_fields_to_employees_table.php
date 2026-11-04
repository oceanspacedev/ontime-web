<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('talenta_id')->nullable()->unique()->after('nip');
            $table->string('gender', 20)->nullable()->after('phone');
            $table->date('birth_date')->nullable()->after('gender');
            $table->string('marital_status', 50)->nullable()->after('birth_date');
            $table->string('tax_status', 20)->nullable()->after('marital_status');
            $table->string('avatar_url', 500)->nullable()->after('tax_status');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropUnique(['talenta_id']);
            $table->dropColumn(['talenta_id', 'gender', 'birth_date', 'marital_status', 'tax_status', 'avatar_url']);
        });
    }
};
