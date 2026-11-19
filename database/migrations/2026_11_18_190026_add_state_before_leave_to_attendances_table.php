<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An existing attendance row that an approved leave turned into a leave row keeps its status and notes
     * from before, so shortening or deleting the leave puts the row back instead of deleting it. A row the
     * leave created itself has none and is removed.
     */
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->json('state_before_leave')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropColumn('state_before_leave');
        });
    }
};
