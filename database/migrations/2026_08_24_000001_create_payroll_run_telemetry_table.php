<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_run_telemetry', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payroll_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('previous_run_id')->nullable()->constrained('payroll_runs')->nullOnDelete();
            $table->string('event', 16);
            $table->string('code', 64)->nullable();
            $table->timestamp('occurred_at');

            $table->index(['payroll_run_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_run_telemetry');
    }
};
