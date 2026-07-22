<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('overtime_decisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pay_period_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('work_date');
            $table->char('candidate_key', 64);
            $table->char('fingerprint', 64);
            $table->string('segment_kind', 32);
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->unsignedInteger('minutes');
            $table->json('rate_minutes');
            $table->string('decision', 16);
            $table->text('reason');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('supersedes_id')->nullable()->unique()->constrained('overtime_decisions')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(
                ['company_id', 'pay_period_id', 'employee_id', 'work_date'],
                'overtime_decisions_context_idx',
            );
            $table->index('candidate_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('overtime_decisions');
    }
};
