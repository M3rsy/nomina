<?php

use App\Models\Company;
use App\Models\Employee;
use App\Models\PayPeriod;
use App\Models\RawMark;

test('stores a normalized manual mark without inventing uploaded file evidence', function () {
    $company = Company::factory()->create();
    $period = PayPeriod::factory()->forCompany($company)->create();
    $employee = Employee::factory()->forCompany($company)->create();

    $mark = RawMark::factory()
        ->forCompany($company)
        ->forPayPeriod($period)
        ->forEmployee($employee)
        ->create([
            'uploaded_file_id' => null,
            'raw_line' => null,
            'row_number' => null,
            'employee_external_id' => $employee->external_id,
            'event_at' => '2026-07-20 14:00:00',
            'source' => RawMark::SOURCE_MANUAL,
            'status' => 'corrected',
            'metadata' => [
                'revisions' => [[
                    'action' => 'manual_create',
                    'reason' => 'Salida no registrada por el reloj',
                ]],
            ],
        ]);

    expect($mark->source)->toBe(RawMark::SOURCE_MANUAL)
        ->and($mark->uploaded_file_id)->toBeNull()
        ->and($mark->uploadedFile)->toBeNull()
        ->and($mark->raw_line)->toBeNull()
        ->and($mark->row_number)->toBeNull()
        ->and($mark->employee->is($employee))->toBeTrue()
        ->and($mark->metadata['revisions'][0]['action'])->toBe('manual_create');
});
