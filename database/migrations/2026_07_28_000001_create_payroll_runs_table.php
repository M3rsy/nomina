<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_runs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('request_key')->unique();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pay_period_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 16)->default('queued');
            $table->unsignedTinyInteger('active_key')->nullable()->default(1);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(['pay_period_id', 'active_key'], 'payroll_runs_one_active');
            $table->index(['company_id', 'pay_period_id', 'status'], 'payroll_runs_context_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_runs');
    }
};
