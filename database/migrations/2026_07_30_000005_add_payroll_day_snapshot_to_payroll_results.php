<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pay_periods', function (Blueprint $table): void {
            $table->unsignedInteger('current_result_generation')->default(1);
            $table->unsignedInteger('authorized_result_generation')->nullable();
        });

        Schema::table('payroll_results', function (Blueprint $table): void {
            $table->dropUnique(['company_id', 'pay_period_id', 'employee_id', 'date']);
            $table->unsignedInteger('result_generation')->default(1)->after('pay_period_id');
            $table->foreignId('supersedes_id')->nullable()->after('result_generation')
                ->constrained('payroll_results')->restrictOnDelete();
            $table->json('day_snapshot')->nullable()->after('calendar_generation');
            $table->char('snapshot_hash', 64)->nullable()->after('day_snapshot');
            $table->unique(
                ['company_id', 'pay_period_id', 'employee_id', 'date', 'result_generation'],
                'payroll_result_generation_unique',
            );
            $table->unique('supersedes_id');
        });

        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared("CREATE TRIGGER payroll_results_reject_update BEFORE UPDATE ON payroll_results BEGIN SELECT RAISE(ABORT, 'payroll_results are insert-only'); END");
            DB::unprepared("CREATE TRIGGER payroll_results_reject_delete BEFORE DELETE ON payroll_results BEGIN SELECT RAISE(ABORT, 'payroll_results are insert-only'); END");
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE FUNCTION reject_payroll_result_mutation() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'payroll_results are insert-only' USING ERRCODE = '23514';
                END;
                $$ LANGUAGE plpgsql;

                CREATE TRIGGER payroll_results_reject_mutation
                BEFORE UPDATE OR DELETE ON payroll_results
                FOR EACH ROW EXECUTE FUNCTION reject_payroll_result_mutation();
                SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS payroll_results_reject_update');
            DB::unprepared('DROP TRIGGER IF EXISTS payroll_results_reject_delete');
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS payroll_results_reject_mutation ON payroll_results');
            DB::unprepared('DROP FUNCTION IF EXISTS reject_payroll_result_mutation()');
        }

        Schema::table('payroll_results', function (Blueprint $table): void {
            $table->dropForeign(['supersedes_id']);
            $table->dropUnique('payroll_result_generation_unique');
            $table->dropUnique(['supersedes_id']);
            $table->dropColumn(['result_generation', 'supersedes_id', 'day_snapshot', 'snapshot_hash']);
            $table->unique(['company_id', 'pay_period_id', 'employee_id', 'date']);
        });

        Schema::table('pay_periods', function (Blueprint $table): void {
            $table->dropColumn(['current_result_generation', 'authorized_result_generation']);
        });
    }
};
