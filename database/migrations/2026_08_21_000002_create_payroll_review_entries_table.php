<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_review_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pay_period_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('work_date');
            $table->string('type', 64);
            $table->string('status', 64);
            $table->string('source_key', 160);
            $table->string('source_fingerprint', 120);
            $table->string('generation', 64);
            $table->timestamp('occurred_at')->nullable();
            $table->json('payload');
            $table->timestamps();

            $table->index(['pay_period_id', 'generation'], 'payroll_review_entries_period_generation_idx');
            $table->index(['pay_period_id', 'type', 'status', 'work_date'], 'payroll_review_entries_review_filter_idx');
            $table->index(['company_id', 'employee_id', 'work_date'], 'payroll_review_entries_employee_date_idx');
            $table->unique(['pay_period_id', 'type', 'source_key', 'generation'], 'payroll_review_entries_source_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_review_entries');
    }
};
