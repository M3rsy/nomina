<?php

use App\Models\PayrollResult;
use App\Services\Payroll\PayrollReportingRowAdapter;

test('reporting adapter reads paid vacation identity from schema v3 snapshots', function () {
    $result = (new PayrollResult)->forceFill([
        'employee_payment_code' => 'P-10',
        'employee_job_title' => 'Analista',
        'day_snapshot' => [
            'schema_version' => 3,
            'work_date' => '2026-09-10',
            'day_type' => 'paid_vacation',
            'employee' => ['external_id' => 'E-10', 'name' => 'Ana López'],
            'attendance' => ['marks' => [], 'worked_minutes' => 0],
            'payable_minutes' => ['ordinary' => 480, 'extra25' => 0, 'extra50' => 0, 'extra75' => 0, 'extra100' => 0],
            'vacation' => ['id' => 30, 'day_id' => 31, 'planned_minutes' => 480],
        ],
    ]);

    $row = (new PayrollReportingRowAdapter)->adapt($result);

    expect($row['day_type'])->toBe('paid_vacation')
        ->and($row['vacation_id'])->toBe(30)
        ->and($row['vacation_day_id'])->toBe(31)
        ->and($row['vacation'])->toBe('{"id":30,"day_id":31,"planned_minutes":480}')
        ->and($row['worked_minutes'])->toBe(0)
        ->and($row['ordinary_minutes'])->toBe(480);
});

test('reporting adapter keeps schema v2 snapshots compatible as attendance days', function () {
    $result = (new PayrollResult)->forceFill([
        'day_snapshot' => [
            'schema_version' => 2,
            'work_date' => '2026-09-09',
            'employee' => [],
            'attendance' => ['marks' => []],
            'payable_minutes' => [],
        ],
    ]);

    $row = (new PayrollReportingRowAdapter)->adapt($result);

    expect($row['status'])->toBe('CURRENT')
        ->and($row['day_type'])->toBe('attendance')
        ->and($row['vacation_id'])->toBeNull()
        ->and($row['vacation'])->toBeNull();
});
