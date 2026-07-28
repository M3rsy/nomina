<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $mismatches = DB::selectOne(<<<'SQL'
            select
                exists (
                    select 1
                    from payroll_results as payroll_result
                    inner join pay_periods as pay_period
                        on pay_period.id = payroll_result.pay_period_id
                    where pay_period.company_id <> payroll_result.company_id
                ) as pay_period_mismatch,
                exists (
                    select 1
                    from payroll_results as payroll_result
                    inner join employees as employee
                        on employee.id = payroll_result.employee_id
                    where employee.company_id <> payroll_result.company_id
                ) as employee_mismatch
            SQL);

        if ($mismatches->pay_period_mismatch || $mismatches->employee_mismatch) {
            throw new RuntimeException(
                'Cannot enforce payroll result tenant invariants: historical cross-company references exist.'
            );
        }

        Schema::table('pay_periods', function (Blueprint $table) {
            $table->unique(['company_id', 'id'], 'pay_periods_company_id_id_unique');
        });
        Schema::table('employees', function (Blueprint $table) {
            $table->unique(['company_id', 'id'], 'employees_company_id_id_unique');
        });
        Schema::table('payroll_results', function (Blueprint $table) {
            $table->foreign(
                ['company_id', 'pay_period_id'],
                'payroll_results_company_pay_period_foreign'
            )->references(['company_id', 'id'])->on('pay_periods')->cascadeOnDelete();
            $table->foreign(
                ['company_id', 'employee_id'],
                'payroll_results_company_employee_foreign'
            )->references(['company_id', 'id'])->on('employees')->cascadeOnDelete();
            $table->dropForeign('payroll_results_pay_period_id_foreign');
            $table->dropForeign('payroll_results_employee_id_foreign');
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        Schema::table('payroll_results', function (Blueprint $table) {
            $table->foreign('pay_period_id')
                ->references('id')->on('pay_periods')->cascadeOnDelete();
            $table->foreign('employee_id')
                ->references('id')->on('employees')->cascadeOnDelete();
            $table->dropForeign('payroll_results_company_pay_period_foreign');
            $table->dropForeign('payroll_results_company_employee_foreign');
        });
        Schema::table('pay_periods', function (Blueprint $table) {
            $table->dropUnique('pay_periods_company_id_id_unique');
        });
        Schema::table('employees', function (Blueprint $table) {
            $table->dropUnique('employees_company_id_id_unique');
        });
    }
};
