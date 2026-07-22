<?php

use App\Models\Employee;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_results', function (Blueprint $table) {
            $table->string('employee_external_id', 50)->nullable()->after('employee_id')->index();
            $table->string('employee_name')->nullable()->after('employee_external_id');
        });

        DB::table('payroll_results')
            ->orderBy('id')
            ->chunkById(200, function ($results): void {
                foreach ($results as $result) {
                    $employee = Employee::query()->find($result->employee_id);

                    DB::table('payroll_results')->where('id', $result->id)->update([
                        'employee_external_id' => $employee?->external_id,
                        'employee_name' => $employee
                            ? trim($employee->first_name.' '.$employee->last_name)
                            : null,
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('payroll_results', function (Blueprint $table) {
            $table->dropColumn(['employee_external_id', 'employee_name']);
        });
    }
};
