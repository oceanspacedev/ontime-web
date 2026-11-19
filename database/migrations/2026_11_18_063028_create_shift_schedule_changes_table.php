<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Log of every roster change: who changed which worker's schedule, when, through which channel
     * and what it was before and after. The schedule and worker ids carry no foreign key, so the
     * history survives the deletion of the schedule or the worker.
     */
    public function up(): void
    {
        Schema::create('shift_schedule_changes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shift_schedule_id')->nullable()->index();
            $table->unsignedBigInteger('employee_id')->nullable()->index();
            $table->unsignedBigInteger('intern_id')->nullable()->index();
            $table->unsignedBigInteger('freelancer_id')->nullable()->index();
            $table->date('date');
            $table->unsignedTinyInteger('sequence')->default(1);
            $table->string('action', 10);
            $table->string('source', 20);
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->string('reason')->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_schedule_changes');
    }
};
