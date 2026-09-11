<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_results', function (Blueprint $table): void {
            $table->string('day_type', 32)->default('attendance')->after('unjustified');
            $table->foreignId('vacation_id')->nullable()->after('day_type')
                ->constrained('vacations')->restrictOnDelete();
            $table->foreignId('vacation_day_id')->nullable()->after('vacation_id')
                ->constrained('vacation_days')->restrictOnDelete();
            $table->index(['company_id', 'day_type', 'date'], 'payroll_results_company_day_type_date_idx');
        });

        $this->restoreSqliteImmutabilityTriggers();
    }

    public function down(): void
    {
        Schema::table('payroll_results', function (Blueprint $table): void {
            $table->dropIndex('payroll_results_company_day_type_date_idx');
            $table->dropConstrainedForeignId('vacation_day_id');
            $table->dropConstrainedForeignId('vacation_id');
            $table->dropColumn('day_type');
        });

        $this->restoreSqliteImmutabilityTriggers();
    }

    private function restoreSqliteImmutabilityTriggers(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            return;
        }

        DB::unprepared('DROP TRIGGER IF EXISTS payroll_results_reject_update');
        DB::unprepared('DROP TRIGGER IF EXISTS payroll_results_reject_delete');
        DB::unprepared("CREATE TRIGGER payroll_results_reject_update BEFORE UPDATE ON payroll_results BEGIN SELECT RAISE(ABORT, 'payroll_results are insert-only'); END");
        DB::unprepared("CREATE TRIGGER payroll_results_reject_delete BEFORE DELETE ON payroll_results BEGIN SELECT RAISE(ABORT, 'payroll_results are insert-only'); END");
    }
};
