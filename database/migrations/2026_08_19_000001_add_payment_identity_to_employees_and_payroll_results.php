<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->string('payment_code', 50)->nullable()->after('external_id');
            $table->unique(['company_id', 'payment_code'], 'employees_company_payment_code_unique');
        });

        Schema::table('payroll_results', function (Blueprint $table): void {
            $table->string('employee_payment_code', 50)->nullable()->after('employee_external_id');
            $table->string('employee_job_title', 100)->nullable()->after('employee_name');
        });

        DB::table('payroll_results')->orderBy('id')->chunkById(500, function ($results): void {
            foreach ($results as $result) {
                $employee = DB::table('employees')->where('id', $result->employee_id)->first();
                if ($employee === null) {
                    continue;
                }
                DB::table('payroll_results')->where('id', $result->id)->update([
                    'employee_payment_code' => $employee->payment_code,
                    'employee_job_title' => $employee->job_title,
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('payroll_results', function (Blueprint $table): void {
            $table->dropColumn(['employee_payment_code', 'employee_job_title']);
        });
        Schema::table('employees', function (Blueprint $table): void {
            $table->dropUnique('employees_company_payment_code_unique');
            $table->dropColumn('payment_code');
        });
    }
};
