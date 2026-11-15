<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Worker numbers (NIP, Talenta ID, NIS, freelancer number) only have to be unique inside a
     * company here; the app keeps them unique inside each Klien. A global index let one client block
     * another client's numbering and learn which numbers exist there.
     */
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropUnique(['nip']);
            $table->dropUnique(['talenta_id']);
            $table->unique(['company_id', 'nip']);
            $table->unique(['company_id', 'talenta_id']);
        });

        Schema::table('interns', function (Blueprint $table) {
            $table->dropUnique(['nis']);
            $table->unique(['company_id', 'nis']);
        });

        Schema::table('freelancers', function (Blueprint $table) {
            $table->dropUnique(['freelancer_number']);
            $table->unique(['company_id', 'freelancer_number']);
        });
    }

    public function down(): void
    {
        Schema::table('freelancers', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'freelancer_number']);
            $table->unique(['freelancer_number']);
        });

        Schema::table('interns', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'nis']);
            $table->unique(['nis']);
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'talenta_id']);
            $table->dropUnique(['company_id', 'nip']);
            $table->unique(['talenta_id']);
            $table->unique(['nip']);
        });
    }
};
