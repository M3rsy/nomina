<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_runs', function (Blueprint $table): void {
            $table->timestamp('lease_expires_at')->nullable()->after('started_at');
            $table->index(['status', 'lease_expires_at'], 'payroll_runs_recovery_idx');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_runs', function (Blueprint $table): void {
            $table->dropIndex('payroll_runs_recovery_idx');
            $table->dropColumn('lease_expires_at');
        });
    }
};
