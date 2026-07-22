<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_exceptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pay_period_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('work_date');
            $table->char('deficit_key', 64);
            $table->char('fingerprint', 64);
            $table->string('segment_kind', 32);
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->unsignedInteger('minutes');
            $table->json('rate_minutes');
            $table->string('decision', 16);
            $table->text('reason');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('supersedes_id')->nullable()->unique()->constrained('attendance_exceptions')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(
                ['company_id', 'pay_period_id', 'employee_id', 'work_date'],
                'attendance_exceptions_context_idx',
            );
            $table->index('deficit_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_exceptions');
    }
};
