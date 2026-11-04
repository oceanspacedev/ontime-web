<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The mobile API used to store "annual" and "permitted" while the panel stores "annual_leave"
     * and "permission", so neither side counted the other's leave against the yearly quota.
     */
    public function up(): void
    {
        DB::table('leave_requests')->where('leave_type', 'annual')->update(['leave_type' => 'annual_leave']);
        DB::table('leave_requests')->where('leave_type', 'permitted')->update(['leave_type' => 'permission']);
    }

    /**
     * The original codes cannot be told apart from leave filed in the panel, so nothing is reverted.
     */
    public function down(): void
    {
        //
    }
};
