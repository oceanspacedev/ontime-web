<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Attendance reminders already sent, so each kind goes to a user at most once per shift date.
     */
    public function up(): void
    {
        Schema::create('attendance_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('kind', 30);
            $table->timestamp('created_at')->nullable();

            $table->unique(['user_id', 'date', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_reminders');
    }
};
