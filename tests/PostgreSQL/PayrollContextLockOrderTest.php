<?php

use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeScheduleAssignment;
use App\Models\PayPeriod;
use App\Models\RawMark;
use App\Models\UploadedFile;
use App\Models\WorkScheduleProfile;
use App\Services\Attendance\EmployeeScheduleAssigner;
use App\Services\Payroll\LockedPayrollContext;
use App\Services\Payroll\PayrollContextLocker;
use App\Services\Payroll\PayrollContextTargets;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function recordPayrollLocks(array &$lockSql): void
{
    DB::listen(function (QueryExecuted $query) use (&$lockSql): void {
        if (str_contains(strtolower($query->sql), 'for update')) {
            $lockSql[] = strtolower($query->sql);
        }
    });
}

test('payroll context targets normalize every lock id list', function () {
    $targets = new PayrollContextTargets(
        payPeriodIds: [3, 1, 3, 2],
        profileIds: [7, 5, 7, 6],
        publicationIds: [11, 9, 11, 10],
        employeeIds: [15, 13, 15, 14],
        assignmentIds: [19, 17, 19, 18],
        rawMarkIds: [23, 21, 23, 22],
    );

    expect($targets->payPeriodIds)->toBe([1, 2, 3])
        ->and($targets->profileIds)->toBe([5, 6, 7])
        ->and($targets->publicationIds)->toBe([9, 10, 11])
        ->and($targets->employeeIds)->toBe([13, 14, 15])
        ->and($targets->assignmentIds)->toBe([17, 18, 19])
        ->and($targets->rawMarkIds)->toBe([21, 22, 23]);
});

test('payroll context uses the canonical lock order at transaction level one', function () {
    $company = Company::factory()->create();
    $periods = PayPeriod::factory()->count(2)->forCompany($company)->create();
    $profiles = WorkScheduleProfile::factory()->count(2)->forCompany($company)->create();
    $publications = $profiles->map->publications->flatten();
    $employees = Employee::factory()->count(2)->forCompany($company)->create();
    $assignments = $employees->map(fn (Employee $employee, int $index) => EmployeeScheduleAssignment::factory()->create([
        'company_id' => $company->id, 'employee_id' => $employee->id,
        'work_schedule_profile_id' => $profiles[$index]->id, 'effective_from' => '2026-01-01',
    ]));
    $file = UploadedFile::factory()->forCompany($company)->forPayPeriod($periods->first())->create();
    $marks = $employees->map(fn (Employee $employee, int $index) => RawMark::factory()->forCompany($company)
        ->forPayPeriod($periods->first())->forUploadedFile($file)->forEmployee($employee)
        ->create(['row_number' => $index + 1]));
    $lockSql = [];
    recordPayrollLocks($lockSql);
    app(PayrollContextLocker::class)->within(
        $company->id,
        fn () => new PayrollContextTargets(
            payPeriodIds: $periods->pluck('id')->reverse()->all(),
            profileIds: $profiles->pluck('id')->reverse()->all(),
            publicationIds: $publications->pluck('id')->reverse()->all(),
            employeeIds: $employees->pluck('id')->reverse()->all(),
            assignmentIds: $assignments->pluck('id')->reverse()->all(),
            rawMarkIds: $marks->pluck('id')->reverse()->all(),
            holidayStart: '2026-01-01',
        ),
        function ($context) use ($periods, $profiles, $publications, $employees, $assignments, $marks): void {
            expect(DB::transactionLevel())->toBe(1)
                ->and($context->payPeriods->keys()->all())->toBe($periods->pluck('id')->all())
                ->and($context->profiles->keys()->all())->toBe($profiles->pluck('id')->all())
                ->and($context->publications->keys()->all())->toBe($publications->pluck('id')->all())
                ->and($context->employees->keys()->all())->toBe($employees->pluck('id')->all())
                ->and($context->assignments->keys()->all())->toBe($assignments->pluck('id')->all())
                ->and($context->rawMarks->keys()->all())->toBe($marks->pluck('id')->all());
        },
    );

    $tables = collect($lockSql)->map(fn (string $sql): string => Str::betweenFirst($sql, 'from "', '"'))->all();
    $expected = explode(',', 'companies,pay_periods,work_schedule_profiles,work_schedule_profile_publications,'.
        'employees,employee_schedule_assignments,raw_marks');
    expect($tables)->toBe($expected)
        ->and(collect($lockSql)->every(fn (string $sql): bool => str_contains($sql, 'order by "id" asc')))->toBeTrue();
});

test('schedule assignment delegates deterministic assignment locks to the payroll context', function () {
    $company = Company::factory()->create();
    $employee = Employee::factory()->forCompany($company)->create();
    $profiles = WorkScheduleProfile::factory()->count(2)->forCompany($company)->create();
    EmployeeScheduleAssignment::factory()->create([
        'company_id' => $company->id, 'employee_id' => $employee->id,
        'work_schedule_profile_id' => $profiles[0]->id, 'effective_from' => '2026-01-01',
    ]);
    $lockSql = [];
    recordPayrollLocks($lockSql);
    app(EmployeeScheduleAssigner::class)->assign($employee, $profiles[1], '2026-02-01', 'Canonical assignment locking');
    $assignmentLock = collect($lockSql)
        ->first(fn (string $sql): bool => str_contains($sql, 'employee_schedule_assignments'));

    expect($assignmentLock)->toContain('where "id" in')
        ->toContain('order by "id" asc');
});

test('employee creation stages writes before assignment and raw mark locks', function () {
    $company = Company::factory()->create();
    $period = PayPeriod::factory()->forCompany($company)->create();
    $profile = WorkScheduleProfile::factory()->forCompany($company)->create();
    $file = UploadedFile::factory()->forCompany($company)->forPayPeriod($period)->create();
    $mark = RawMark::factory()->forCompany($company)->forPayPeriod($period)->forUploadedFile($file)->create();
    $sql = [];
    /** @var ?LockedPayrollContext $escaped */
    $escaped = null;
    DB::listen(function (QueryExecuted $query) use (&$sql): void {
        $sql[] = strtolower($query->sql);
    });

    app(PayrollContextLocker::class)->withinEmployeeCreation(
        $company->id,
        fn () => new PayrollContextTargets(profileIds: [$profile->id], rawMarkIds: [$mark->id]),
        fn () => Employee::factory()->forCompany($company)->make()->getAttributes(),
        function ($context): void {
            EmployeeScheduleAssignment::factory()->create([
                'company_id' => $context->company->id,
                'employee_id' => $context->employees->sole()->id,
                'work_schedule_profile_id' => $context->profiles->sole()->id,
            ]);
        },
        function ($context) use (&$escaped): void {
            $escaped = $context;
            $context->rawMarks->sole()->update(['status' => 'corrected']);
        },
    );

    $employeeInsert = collect($sql)->search(fn (string $query): bool => str_starts_with($query, 'insert into "employees"'));
    $assignmentInsert = collect($sql)->search(fn (string $query): bool => str_starts_with($query, 'insert into "employee_schedule_assignments"'));
    $rawMarkLock = collect($sql)->search(fn (string $query): bool => str_contains($query, 'from "raw_marks"') && str_contains($query, 'for update'));

    expect($employeeInsert)->toBeInt()
        ->and($assignmentInsert)->toBeInt()
        ->and($rawMarkLock)->toBeInt()
        ->and($employeeInsert)->toBeLessThan($assignmentInsert)
        ->and($assignmentInsert)->toBeLessThan($rawMarkLock)
        ->and(fn () => $escaped->assertActive())->toThrow(LogicException::class);
});
