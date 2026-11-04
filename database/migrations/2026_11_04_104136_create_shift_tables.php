<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Work shifts per company, a default shift per worker, and a dated schedule that overrides it.
     * Check-in resolves: schedule for the day, then the default shift, then the company policy hours.
     */
    public function up(): void
    {
        Schema::create('shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 20)->nullable();
            $table->time('start_time');
            $table->time('end_time');
            $table->unsignedInteger('late_tolerance_minutes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'name']);
        });

        foreach (['employees', 'interns', 'freelancers'] as $workerTable) {
            Schema::table($workerTable, function (Blueprint $table) {
                $table->foreignId('shift_id')->nullable()->after('division_id')->constrained('shifts')->nullOnDelete();
            });
        }

        Schema::create('shift_schedules', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->foreignId('employee_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('intern_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('freelancer_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('shift_id')->nullable()->constrained('shifts')->nullOnDelete();
            $table->boolean('is_day_off')->default(false);
            $table->string('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['employee_id', 'date']);
            $table->unique(['intern_id', 'date']);
            $table->unique(['freelancer_id', 'date']);
            $table->index('date');
        });

        Schema::table('attendances', function (Blueprint $table) {
            $table->foreignId('shift_id')->nullable()->after('freelancer_id')->constrained('shifts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropConstrainedForeignId('shift_id');
        });

        Schema::dropIfExists('shift_schedules');

        foreach (['employees', 'interns', 'freelancers'] as $workerTable) {
            Schema::table($workerTable, function (Blueprint $table) {
                $table->dropConstrainedForeignId('shift_id');
            });
        }

        Schema::dropIfExists('shifts');
    }
};
