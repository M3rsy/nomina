<?php

use App\Models\PayrollResult;
use Illuminate\Support\Facades\DB;

test('payment identity migration preserves insert-only historical payroll results', function () {
    $result = PayrollResult::factory()->create([
        'employee_payment_code' => '00042',
        'employee_job_title' => 'Operador',
    ]);
    $migration = require database_path('migrations/2026_08_19_000001_add_payment_identity_to_employees_and_payroll_results.php');

    $migration->down();
    $migration->up();

    expect(DB::table('payroll_results')->where('id', $result->id)->first())
        ->employee_payment_code->toBeNull()
        ->employee_job_title->toBeNull();
});
