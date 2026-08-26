<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_run_telemetry', function (Blueprint $table): void {
            $table->unsignedBigInteger('duration_ms')->nullable();
            $table->unsignedBigInteger('queue_wait_ms')->nullable();
            $table->unsignedBigInteger('db_time_ms')->nullable();
            $table->unsignedBigInteger('query_count')->nullable();
            $table->unsignedBigInteger('peak_memory_mb')->nullable();
            $table->unsignedBigInteger('employee_count')->nullable();
            $table->unsignedBigInteger('day_count')->nullable();
            $table->unsignedBigInteger('result_count')->nullable();
            $table->unsignedBigInteger('inserted_count')->nullable();
            $table->unsignedBigInteger('reused_count')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('payroll_run_telemetry', function (Blueprint $table): void {
            $table->dropColumn([
                'duration_ms',
                'queue_wait_ms',
                'db_time_ms',
                'query_count',
                'peak_memory_mb',
                'employee_count',
                'day_count',
                'result_count',
                'inserted_count',
                'reused_count',
            ]);
        });
    }
};
