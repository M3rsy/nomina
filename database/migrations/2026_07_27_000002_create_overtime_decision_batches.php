<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('overtime_decision_batches', function (Blueprint $table): void {
            $table->id();
            $table->uuid('request_key')->unique();
            $table->char('payload_hash', 64);
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pay_period_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('decision', 16);
            $table->text('reason');
            $table->string('status', 32)->default('queued');
            $table->unsignedInteger('total_items');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'pay_period_id', 'status'], 'overtime_batches_context_idx');
        });

        Schema::create('overtime_decision_batch_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('batch_id')->constrained('overtime_decision_batches')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('work_date');
            $table->char('candidate_key', 64);
            $table->char('fingerprint', 64);
            $table->string('status', 16)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->unique(
                ['batch_id', 'employee_id', 'work_date', 'candidate_key'],
                'overtime_batch_items_candidate_unique',
            );
            $table->index(['batch_id', 'status', 'id'], 'overtime_batch_items_progress_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('overtime_decision_batch_items');
        Schema::dropIfExists('overtime_decision_batches');
    }
};
