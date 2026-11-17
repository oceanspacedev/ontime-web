<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tukar Shift: a worker (the requester, on employee_id / intern_id / freelancer_id) asks a colleague of the
     * same company (the counterpart) to trade their schedules, or to take over the requester's shift (give away).
     * The colleague accepts first, then the company's shift_swap approval flow decides.
     */
    public function up(): void
    {
        Schema::create('shift_swap_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('intern_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('freelancer_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('counterpart_employee_id')->nullable()->constrained('employees')->cascadeOnDelete();
            $table->foreignId('counterpart_intern_id')->nullable()->constrained('interns')->cascadeOnDelete();
            $table->foreignId('counterpart_freelancer_id')->nullable()->constrained('freelancers')->cascadeOnDelete();
            $table->string('type', 20)->default('swap');
            $table->date('requester_date');
            $table->date('counterpart_date')->nullable();
            $table->text('reason')->nullable();
            $table->json('schedule_snapshot')->nullable();
            $table->string('status', 30)->default('awaiting_colleague');
            $table->unsignedInteger('current_step')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamp('counterpart_responded_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->foreignId('applied_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'requester_date']);
            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_swap_requests');
    }
};
