<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A flow with a division overrides the company's flow for workers in that division and below.
     */
    public function up(): void
    {
        Schema::table('approval_flows', function (Blueprint $table) {
            $table->foreignId('division_id')->nullable()->after('company_id')->constrained()->cascadeOnDelete();
            $table->index(['company_id', 'division_id', 'request_type']);
        });
    }

    public function down(): void
    {
        Schema::table('approval_flows', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'division_id', 'request_type']);
            $table->dropConstrainedForeignId('division_id');
        });
    }
};
