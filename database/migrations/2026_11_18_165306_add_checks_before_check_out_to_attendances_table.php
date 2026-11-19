<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * While only the check-out of a row waits for approval, the verification results of its settled
     * check-in (out of area, fake location, face and liveness) are kept aside, so rejecting that check-out
     * puts them back instead of leaving the flags of a punch that no longer exists on the row.
     */
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->json('checks_before_check_out')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropColumn('checks_before_check_out');
        });
    }
};
