<?php

use App\Models\Company;
use App\Models\Employee;
use App\Models\PayPeriod;
use App\Models\RawMark;
use App\Models\UploadedFile;
use App\Models\WorkSchedule;
use App\Models\WorkScheduleProfile;
use App\Services\Attendance\AttendanceFactGenerationTracker;
use App\Services\Attendance\EmployeeScheduleAssigner;
use App\Services\Attendance\ShiftOccurrenceResolver;
use App\Services\FileValidator;
use App\Services\Parsers\RawMarkPayload;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

function buildPayload(string $employeeId, string $eventAt, int $rowNumber): RawMarkPayload
{
    return new RawMarkPayload(
        employee_external_id: $employeeId,
        event_at: Carbon::parse($eventAt),
        raw_line: "{$employeeId}\t{$eventAt}",
        row_number: $rowNumber,
        source: 'glg',
    );
}

test('validator marks duplicate records by employee and event time', function () {
    $company = Company::factory()->create();
    $payPeriod = PayPeriod::factory()->forCompany($company)->create([
        'start_date' => '2026-01-01',
        'end_date' => '2026-01-31',
    ]);
    $employee = Employee::factory()->forCompany($company)->create(['external_id' => '13767']);

    $uploadedFile = UploadedFile::factory()->forCompany($company)->forPayPeriod($payPeriod)->create([
        'status' => 'pending',
    ]);

    $records = collect([
        buildPayload('13767', '2026-01-19 14:53:50', 1),
        buildPayload('13767', '2026-01-19 14:53:50', 2),
    ]);

    $validator = app(FileValidator::class);
    $report = $validator->validate($uploadedFile, $records);

    expect($report->counts['duplicate'])->toBe(1);
    expect($report->counts['valid'])->toBe(1);
    expect(app(AttendanceFactGenerationTracker::class)->current($employee, '2026-01-19'))->toBe(1);
    expect(RawMark::where('uploaded_file_id', $uploadedFile->id)->where('status', 'duplicate')->exists())->toBeTrue();
    expect(RawMark::where('uploaded_file_id', $uploadedFile->id)->where('status', 'valid')->exists())->toBeTrue();
});

test('validator locks planned payroll parents before creating raw marks', function () {
    $company = Company::factory()->create(['id' => 301]);
    $uploadPeriod = PayPeriod::factory()->forCompany($company)->create([
        'id' => 902,
        'start_date' => '2026-01-16',
        'end_date' => '2026-01-31',
    ]);
    PayPeriod::factory()->forCompany($company)->create([
        'id' => 401,
        'start_date' => '2026-01-01',
        'end_date' => '2026-01-15',
    ]);
    Employee::factory()->forCompany($company)->create(['id' => 702, 'external_id' => '200']);
    Employee::factory()->forCompany($company)->create(['id' => 209, 'external_id' => '100']);
    $uploadedFile = UploadedFile::factory()->forCompany($company)->forPayPeriod($uploadPeriod)->create();

    DB::flushQueryLog();
    DB::enableQueryLog();

    app(FileValidator::class)->validate($uploadedFile, collect([
        buildPayload('200', '2026-01-20 08:00:00', 1),
        buildPayload('100', '2026-01-15 08:00:00', 2),
    ]));

    $queries = collect(DB::getQueryLog());
    DB::disableQueryLog();
    $companyLock = $queries->search(fn (array $query): bool => str_contains($query['query'], 'from "companies"')
        && $query['bindings'] === [301]);
    $periodLocks = $queries->search(fn (array $query): bool => str_contains($query['query'], 'from "pay_periods"')
        && str_contains($query['query'], 'order by "id" asc')
        && $query['bindings'] === [401, 902]);
    $employeeLocks = $queries->search(fn (array $query): bool => str_contains($query['query'], 'from "employees"')
        && str_contains($query['query'], 'order by "id" asc')
        && $query['bindings'] === [209, 702]);
    $firstRawMarkWrite = $queries->search(fn (array $query): bool => str_starts_with(
        $query['query'],
        'insert into "raw_marks"',
    ));

    expect($companyLock)->toBeInt()
        ->and($periodLocks)->toBeInt()
        ->and($employeeLocks)->toBeInt()
        ->and($firstRawMarkWrite)->toBeInt()
        ->and($companyLock < $periodLocks)->toBeTrue()
        ->and($periodLocks < $employeeLocks)->toBeTrue()
        ->and($employeeLocks < $firstRawMarkWrite)->toBeTrue();
});

test('validator advances attendance generations in canonical employee and work-date order', function () {
    $company = Company::factory()->create(['id' => 302]);
    $payPeriod = PayPeriod::factory()->forCompany($company)->create([
        'id' => 903,
        'start_date' => '2026-01-01',
        'end_date' => '2026-01-31',
    ]);
    $firstEmployee = Employee::factory()->forCompany($company)->create([
        'id' => 205,
        'external_id' => '100',
    ]);
    $secondEmployee = Employee::factory()->forCompany($company)->create([
        'id' => 701,
        'external_id' => '200',
    ]);
    $uploadedFile = UploadedFile::factory()->forCompany($company)->forPayPeriod($payPeriod)->create();

    DB::flushQueryLog();
    DB::enableQueryLog();

    $report = app(FileValidator::class)->validate($uploadedFile, collect([
        buildPayload('200', '2026-01-18 08:00:00', 1),
        buildPayload('100', '2026-01-21 08:00:00', 2),
        buildPayload('100', '2026-01-19 08:00:00', 3),
        buildPayload('200', '2026-01-18 17:00:00', 4),
        buildPayload('100', '2026-01-19 17:00:00', 5),
    ]));

    $generationAttempts = collect(DB::getQueryLog())
        ->filter(fn (array $query): bool => str_starts_with(
            $query['query'],
            'insert or ignore into "attendance_fact_generations"',
        ))
        ->map(fn (array $query): array => [
            'employee_id' => $query['bindings'][1],
            'work_date' => $query['bindings'][2],
        ])
        ->values()
        ->all();
    DB::disableQueryLog();
    $generations = app(AttendanceFactGenerationTracker::class);

    expect($report->counts['valid'])->toBe(5)
        ->and($generationAttempts)->toBe([
            ['employee_id' => 205, 'work_date' => '2026-01-19'],
            ['employee_id' => 205, 'work_date' => '2026-01-21'],
            ['employee_id' => 701, 'work_date' => '2026-01-18'],
        ])
        ->and($generations->current($firstEmployee, '2026-01-19'))->toBe(2)
        ->and($generations->current($firstEmployee, '2026-01-21'))->toBe(1)
        ->and($generations->current($secondEmployee, '2026-01-18'))->toBe(2);
});

test('validator detects duplicate observations across payroll periods', function () {
    $company = Company::factory()->create();
    $previousPeriod = PayPeriod::factory()->forCompany($company)->create([
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-20',
    ]);
    $currentPeriod = PayPeriod::factory()->forCompany($company)->create([
        'start_date' => '2026-07-21',
        'end_date' => '2026-07-31',
    ]);
    $employee = Employee::factory()->forCompany($company)->create(['external_id' => '13767']);
    $previousFile = UploadedFile::factory()->forCompany($company)->forPayPeriod($previousPeriod)->create();
    $currentFile = UploadedFile::factory()->forCompany($company)->forPayPeriod($currentPeriod)->create();

    RawMark::factory()->forCompany($company)->forPayPeriod($previousPeriod)
        ->forUploadedFile($previousFile)->forEmployee($employee)->create([
            'employee_external_id' => $employee->external_id,
            'event_at' => '2026-07-21 06:00:00',
            'status' => 'valid',
        ]);

    $report = app(FileValidator::class)->validate($currentFile, collect([
        buildPayload('13767', '2026-07-21 06:00:00', 1),
    ]));

    expect($report->counts['duplicate'])->toBe(1)
        ->and($report->counts['valid'])->toBe(0)
        ->and(RawMark::where('uploaded_file_id', $currentFile->id)->sole()->status)->toBe('duplicate');
});

test('validator marks out of period records', function () {
    $company = Company::factory()->create();
    $payPeriod = PayPeriod::factory()->forCompany($company)->create([
        'start_date' => '2026-01-01',
        'end_date' => '2026-01-31',
    ]);
    Employee::factory()->forCompany($company)->create(['external_id' => '13767']);

    $uploadedFile = UploadedFile::factory()->forCompany($company)->forPayPeriod($payPeriod)->create([
        'status' => 'pending',
    ]);

    $records = collect([
        buildPayload('13767', '2026-02-05 08:00:00', 1),
    ]);

    $validator = app(FileValidator::class);
    $report = $validator->validate($uploadedFile, $records);

    expect($report->counts['out_of_period'])->toBe(1);
    expect($report->counts['valid'])->toBe(0);
    expect(RawMark::where('status', 'out_of_period')->exists())->toBeTrue();
});

test('validator accepts a next-day exit whose overnight work date is inside the period', function () {
    $company = Company::factory()->create();
    $profile = WorkScheduleProfile::factory()->forCompany($company)->create();
    WorkSchedule::factory()->forProfile($profile)->create([
        'day_of_week' => 1,
        'start_time' => '18:00',
        'end_time' => '06:00',
        'base_ordinary_hours' => 12,
    ]);
    WorkSchedule::factory()->forProfile($profile)->create([
        'day_of_week' => 2,
        'is_working_day' => false,
        'start_time' => null,
        'end_time' => null,
        'base_ordinary_hours' => 0,
    ]);
    $employee = Employee::factory()->forCompany($company)->create(['external_id' => '13767']);
    app(EmployeeScheduleAssigner::class)->assign($employee, $profile, '2026-07-01', 'Turno nocturno');
    $payPeriod = PayPeriod::factory()->forCompany($company)->create([
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-20',
        'status' => 'draft',
    ]);
    $uploadedFile = UploadedFile::factory()->forCompany($company)->forPayPeriod($payPeriod)->create([
        'status' => 'pending',
    ]);

    $report = app(FileValidator::class)->validate($uploadedFile, collect([
        buildPayload('13767', '2026-07-20 18:00:00', 1),
        buildPayload('13767', '2026-07-21 10:00:00', 2),
    ]));
    $occurrence = app(ShiftOccurrenceResolver::class)->resolve($employee, '2026-07-20');

    expect($report->counts['valid'])->toBe(2)
        ->and($report->counts['out_of_period'])->toBe(0)
        ->and(app(AttendanceFactGenerationTracker::class)->current($employee, '2026-07-20'))->toBe(2)
        ->and(RawMark::where('uploaded_file_id', $uploadedFile->id)->pluck('status')->all())
        ->toBe(['valid', 'valid'])
        ->and($occurrence->status)->toBe('resolved')
        ->and($occurrence->entryMark()?->event_at->toDateTimeString())->toBe('2026-07-20 18:00:00')
        ->and($occurrence->exitMark()?->event_at->toDateTimeString())->toBe('2026-07-21 10:00:00');
});

test('validator marks unknown employee records', function () {
    $company = Company::factory()->create();
    $payPeriod = PayPeriod::factory()->forCompany($company)->create([
        'start_date' => '2026-01-01',
        'end_date' => '2026-01-31',
    ]);
    Employee::factory()->forCompany($company)->create(['external_id' => '13767']);

    $uploadedFile = UploadedFile::factory()->forCompany($company)->forPayPeriod($payPeriod)->create([
        'status' => 'pending',
    ]);

    $records = collect([
        buildPayload('99999', '2026-01-19 14:53:50', 1),
    ]);

    $validator = app(FileValidator::class);
    $report = $validator->validate($uploadedFile, $records);

    expect($report->counts['unknown_employee'])->toBe(1);
    expect($report->counts['valid'])->toBe(0);
    expect(RawMark::where('status', 'unknown_employee')->exists())->toBeTrue();
});

test('validator sets file status valid when all records are valid', function () {
    $company = Company::factory()->create();
    $payPeriod = PayPeriod::factory()->forCompany($company)->create([
        'start_date' => '2026-01-01',
        'end_date' => '2026-01-31',
    ]);
    Employee::factory()->forCompany($company)->create(['external_id' => '13767']);

    $uploadedFile = UploadedFile::factory()->forCompany($company)->forPayPeriod($payPeriod)->create([
        'status' => 'pending',
    ]);

    $records = collect([
        buildPayload('13767', '2026-01-19 14:53:50', 1),
    ]);

    $validator = app(FileValidator::class);
    $validator->validate($uploadedFile, $records);

    $uploadedFile->refresh();
    expect($uploadedFile->status)->toBe('valid');
    expect($uploadedFile->validation_summary['total'])->toBe(1);
    expect($uploadedFile->validation_summary['valid'])->toBe(1);
});

test('validator sets file status valid_with_warnings when issues exist but some valid', function () {
    $company = Company::factory()->create();
    $payPeriod = PayPeriod::factory()->forCompany($company)->create([
        'start_date' => '2026-01-01',
        'end_date' => '2026-01-31',
    ]);
    Employee::factory()->forCompany($company)->create(['external_id' => '13767']);
    Employee::factory()->forCompany($company)->create(['external_id' => '44']);

    $uploadedFile = UploadedFile::factory()->forCompany($company)->forPayPeriod($payPeriod)->create([
        'status' => 'pending',
    ]);

    $records = collect([
        buildPayload('13767', '2026-01-19 14:53:50', 1),
        buildPayload('99999', '2026-01-19 14:53:50', 2),
    ]);

    $validator = app(FileValidator::class);
    $validator->validate($uploadedFile, $records);

    $uploadedFile->refresh();
    expect($uploadedFile->status)->toBe('valid_with_warnings');
});

test('validator sets file status invalid when no valid records', function () {
    $company = Company::factory()->create();
    $payPeriod = PayPeriod::factory()->forCompany($company)->create([
        'start_date' => '2026-01-01',
        'end_date' => '2026-01-31',
    ]);

    $uploadedFile = UploadedFile::factory()->forCompany($company)->forPayPeriod($payPeriod)->create([
        'status' => 'pending',
    ]);

    $records = collect([
        buildPayload('99999', '2026-01-19 14:53:50', 1),
    ]);

    $validator = app(FileValidator::class);
    $validator->validate($uploadedFile, $records);

    $uploadedFile->refresh();
    expect($uploadedFile->status)->toBe('invalid');
});

test('validator ignores soft-deleted locked periods when an active period permits the import', function () {
    $company = Company::factory()->create();
    $deletedPeriod = PayPeriod::factory()->forCompany($company)->create([
        'start_date' => '2026-01-01',
        'end_date' => '2026-01-31',
        'status' => 'exported',
    ]);
    $currentPeriod = PayPeriod::factory()->forCompany($company)->create([
        'start_date' => '2026-01-01',
        'end_date' => '2026-01-31',
        'status' => 'draft',
    ]);
    $deletedPeriod->delete();
    Employee::factory()->forCompany($company)->create(['external_id' => '13767']);
    $uploadedFile = UploadedFile::factory()->forCompany($company)->forPayPeriod($currentPeriod)->create([
        'status' => 'pending',
    ]);

    $report = app(FileValidator::class)->validate($uploadedFile, collect([
        buildPayload('13767', '2026-01-19 14:53:50', 1),
    ]));

    expect($report->counts['valid'])->toBe(1)
        ->and($report->counts['invalid_row'])->toBe(0)
        ->and($report->rowStatuses)->toBe([1 => 'valid'])
        ->and($uploadedFile->refresh()->status)->toBe('valid');
});

test('validator cannot add a mark to a locked overnight work date', function () {
    $company = Company::factory()->create();
    $profile = WorkScheduleProfile::factory()->forCompany($company)->create();
    WorkSchedule::factory()->forProfile($profile)->create([
        'day_of_week' => 1,
        'start_time' => '18:00',
        'end_time' => '06:00',
        'base_ordinary_hours' => 12,
    ]);
    $employee = Employee::factory()->forCompany($company)->create(['external_id' => '13767']);
    app(EmployeeScheduleAssigner::class)->assign($employee, $profile, '2026-07-01', 'Turno nocturno');

    $lockedPeriod = PayPeriod::factory()->forCompany($company)->create([
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-20',
        'status' => 'exported',
    ]);
    $openPeriod = PayPeriod::factory()->forCompany($company)->create([
        'start_date' => '2026-07-21',
        'end_date' => '2026-07-31',
        'status' => 'draft',
    ]);
    $lockedFile = UploadedFile::factory()->forCompany($company)->forPayPeriod($lockedPeriod)->create();
    $openFile = UploadedFile::factory()->forCompany($company)->forPayPeriod($openPeriod)->create();
    $newFile = UploadedFile::factory()->forCompany($company)->forPayPeriod($openPeriod)->create([
        'status' => 'pending',
    ]);

    RawMark::factory()->forCompany($company)->forPayPeriod($lockedPeriod)
        ->forUploadedFile($lockedFile)->forEmployee($employee)->create([
            'employee_external_id' => $employee->external_id,
            'event_at' => '2026-07-20 18:00:00',
            'status' => 'valid',
        ]);
    RawMark::factory()->forCompany($company)->forPayPeriod($openPeriod)
        ->forUploadedFile($openFile)->forEmployee($employee)->create([
            'employee_external_id' => $employee->external_id,
            'event_at' => '2026-07-21 06:00:00',
            'status' => 'valid',
        ]);

    $report = app(FileValidator::class)->validate($newFile, collect([
        buildPayload('13767', '2026-07-21 05:30:00', 1),
    ]));

    $newMark = RawMark::where('uploaded_file_id', $newFile->id)->sole();

    expect($report->counts['invalid_row'])->toBe(1)
        ->and($report->counts['valid'])->toBe(0)
        ->and($newMark->status)->toBe('invalid')
        ->and($newMark->notes)->toBe('La fecha laboral pertenece a un período bloqueado.')
        ->and(app(ShiftOccurrenceResolver::class)->resolve($employee, '2026-07-20')->marks)->toHaveCount(2);
});

test('validator rejects an imported mark that would break an active manual pair', function () {
    $company = Company::factory()->create();
    $profile = WorkScheduleProfile::factory()->forCompany($company)->create();
    WorkSchedule::factory()->forProfile($profile)->create(['day_of_week' => 1]);
    $employee = Employee::factory()->forCompany($company)->create(['external_id' => '13767']);
    app(EmployeeScheduleAssigner::class)->assign($employee, $profile, '2026-01-01', 'Jornada diurna');
    $payPeriod = PayPeriod::factory()->forCompany($company)->create([
        'start_date' => '2026-01-01',
        'end_date' => '2026-01-31',
        'status' => 'draft',
    ]);
    $sourceFile = UploadedFile::factory()->forCompany($company)->forPayPeriod($payPeriod)->create();
    $newFile = UploadedFile::factory()->forCompany($company)->forPayPeriod($payPeriod)->create([
        'status' => 'pending',
    ]);

    RawMark::factory()->forCompany($company)->forPayPeriod($payPeriod)
        ->forUploadedFile($sourceFile)->forEmployee($employee)->create([
            'employee_external_id' => $employee->external_id,
            'event_at' => '2026-01-05 06:00:00',
            'status' => 'valid',
        ]);
    RawMark::factory()->forCompany($company)->forPayPeriod($payPeriod)
        ->forEmployee($employee)->create([
            'uploaded_file_id' => null,
            'employee_external_id' => $employee->external_id,
            'event_at' => '2026-01-05 14:00:00',
            'raw_line' => null,
            'source' => RawMark::SOURCE_MANUAL,
            'row_number' => null,
            'status' => 'corrected',
        ]);
    $generations = app(AttendanceFactGenerationTracker::class);
    $generations->advance($employee, '2026-01-05');
    $generations->advance($employee, '2026-01-05');

    $report = app(FileValidator::class)->validate($newFile, collect([
        buildPayload('13767', '2026-01-05 12:00:00', 1),
    ]));
    $newMark = RawMark::where('uploaded_file_id', $newFile->id)->sole();
    $occurrence = app(ShiftOccurrenceResolver::class)->resolve($employee, '2026-01-05');

    expect($report->counts['invalid_row'])->toBe(1)
        ->and($report->counts['valid'])->toBe(0)
        ->and($newMark->status)->toBe('invalid')
        ->and($newMark->notes)->toBe('La importación rompería un par con una marca manual auditada.')
        ->and($occurrence->status)->toBe('resolved')
        ->and($occurrence->marks)->toHaveCount(2)
        ->and($generations->current($employee, '2026-01-05'))->toBe(2);
});
