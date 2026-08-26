<?php

use App\Livewire\Nomina\OvertimeReviewPanel;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\OvertimeDecision;
use App\Models\PayPeriod;
use App\Models\PayrollReviewEntry;
use App\Models\RawMark;
use App\Models\UploadedFile;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Models\WorkScheduleProfile;
use App\Services\Attendance\AttendanceReviewQuery;
use App\Services\Attendance\EmployeeScheduleAssigner;
use App\Services\Attendance\OvertimeDecisionRecorder;
use App\Services\CurrentCompany;
use App\Services\Payroll\OvertimeReviewReader;
use App\Services\Payroll\PayrollReviewProjection;
use Database\Seeders\PermissionRoleSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(PermissionRoleSeeder::class);
});

test('payroll review rebuild command is idempotent', function () {
    $context = payrollReviewProjectionFixture();

    Artisan::call('payroll:review:rebuild', ['pay_period_id' => $context['period']->id]);
    Artisan::call('payroll:review:rebuild', ['pay_period_id' => $context['period']->id]);

    expect(PayrollReviewEntry::query()->where('pay_period_id', $context['period']->id)->count())->toBe(1)
        ->and(PayrollReviewEntry::query()->sole()->type)->toBe('overtime_candidate');
});

test('payroll review screen reads fresh projected overtime rows', function () {
    $context = payrollReviewProjectionFixture();
    Artisan::call('payroll:review:rebuild', ['pay_period_id' => $context['period']->id]);
    $this->actingAs($context['actor']);

    Livewire::test(OvertimeReviewPanel::class, ['payPeriod' => $context['period']->fresh()])
        ->assertViewHas('overtimeRows', fn ($rows) => $rows->total() === 1)
        ->assertViewHas('overtimeGroups', fn ($groups) => $groups->count() === 1)
        ->assertSee('María Guardia')
        ->assertSee('Salida posterior')
        ->assertSee('30 min · 0,50 h');
});

test('payroll review screen falls back to legacy calculation when projection is unavailable', function () {
    $context = payrollReviewProjectionFixture();
    $this->actingAs($context['actor']);

    Livewire::test(OvertimeReviewPanel::class, ['payPeriod' => $context['period']])
        ->assertViewHas('overtimeRows', fn ($rows) => $rows->total() === 1)
        ->assertSee('Salida posterior');
});

test('overtime review reader refreshes canonical rows after a decision and mark mutation', function () {
    $context = payrollReviewProjectionFixture();
    Artisan::call('payroll:review:rebuild', ['pay_period_id' => $context['period']->id]);
    $reader = app(OvertimeReviewReader::class);
    $filters = ['search' => '', 'status' => 'all', 'date' => '', 'rate' => ''];

    expect($reader->forPeriod($context['period'], null, $filters, 1)['rows']->total())->toBe(1);

    $candidate = app(AttendanceReviewQuery::class)
        ->forPeriod($context['period'])->sole()->analysis->overtimeCandidates->sole();
    app(OvertimeDecisionRecorder::class)->decide(
        $context['period'], $context['employee'], '2026-07-20', $candidate->key,
        OvertimeDecision::REJECTED, 'Fresh reader check', $context['actor'],
    );

    expect($reader->forPeriod($context['period'], null, $filters, 1)['rows']->sole()['decision']->decision)
        ->toBe(OvertimeDecision::REJECTED);

    RawMark::query()->where('pay_period_id', $context['period']->id)->latest('id')->firstOrFail()->update(['status' => 'deleted']);

    expect($reader->forPeriod($context['period'], null, $filters, 1)['rows']->total())->toBe(0);
});

test('payroll review projection generation includes assignment publication and holiday context', function () {
    $context = payrollReviewProjectionFixture();
    $projection = app(PayrollReviewProjection::class);
    $baseline = $projection->generation($context['period']);

    $assignment = $context['employee']->scheduleAssignments()->sole();
    $assignment->update(['reason' => 'Updated jornada assignment']);
    $afterAssignment = $projection->generation($context['period']);
    $publication = $context['profile']->publications()->sole();
    $publication->update(['payroll_policy_key' => 'duration-first-v2']);
    $afterPublication = $projection->generation($context['period']);
    Holiday::factory()->forCompany($context['company'])->create(['date' => '2026-07-20']);

    expect($projection->generation($context['period']))->not->toBe($afterPublication)
        ->and($afterPublication)->not->toBe($afterAssignment)
        ->and($afterAssignment)->not->toBe($baseline)
        ->and($projection->generation($context['period']))->toBe($projection->generation($context['period']));
});

test('projected overtime pagination filters in SQL before hydrating the selected page', function () {
    $company = Company::factory()->create();
    $period = PayPeriod::factory()->forCompany($company)->create();
    app(CurrentCompany::class)->set($company);
    $generation = 'projected-overtime-pagination';

    foreach (range(1, 60) as $number) {
        $employee = Employee::factory()->forCompany($company)->create([
            'first_name' => 'Paged',
            'last_name' => sprintf('Employee %03d', $number),
            'external_id' => sprintf('PAG-%03d', $number),
        ]);

        PayrollReviewEntry::query()->create(projectedOvertimeEntryAttributes(
            companyId: $company->id,
            payPeriodId: $period->id,
            employeeId: $employee->id,
            generation: $generation,
            sourceKey: sprintf('candidate-%03d', $number),
            rate: 'extra50',
        ));
    }

    DB::flushQueryLog();
    DB::enableQueryLog();

    try {
        $data = app(PayrollReviewProjection::class)->overtimeRows($period, $generation, [
            'search' => 'paged',
            'status' => 'pending',
            'date' => '2026-07-20',
            'rate' => 'extra50',
        ], 2);
        $queries = DB::getQueryLog();
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }

    $entryQueries = collect($queries)
        ->filter(fn (array $query): bool => str_contains(strtolower($query['query']), 'from "payroll_review_entries"'));
    $pageQuery = $entryQueries->first(
        fn (array $query): bool => str_contains(strtolower($query['query']), 'limit 25 offset 25'),
    );

    expect($data['rows']->total())->toBe(60)
        ->and($data['rows']->currentPage())->toBe(2)
        ->and($data['rows']->perPage())->toBe(25)
        ->and($data['groups']->pluck('employee.external_id')->all())
        ->toBe(array_map(fn (int $number): string => sprintf('PAG-%03d', $number), range(26, 50)))
        ->and($pageQuery)->not->toBeNull()
        ->and(strtolower($pageQuery['query']))->toContain('"rate_extra50_minutes" > ?');
});

test('projected overtime SQL filters preserve status date search and rate parity', function () {
    $company = Company::factory()->create();
    $period = PayPeriod::factory()->forCompany($company)->create();
    app(CurrentCompany::class)->set($company);
    $generation = 'projected-overtime-filter-parity';
    $matchingEmployee = Employee::factory()->forCompany($company)->create([
        'first_name' => 'Rate', 'last_name' => 'Match', 'external_id' => 'RATE-50',
    ]);
    $otherEmployee = Employee::factory()->forCompany($company)->create();

    PayrollReviewEntry::query()->create(projectedOvertimeEntryAttributes(
        companyId: $company->id, payPeriodId: $period->id, employeeId: $matchingEmployee->id,
        generation: $generation, sourceKey: 'matching-candidate', rate: 'extra50',
    ));
    PayrollReviewEntry::query()->create(projectedOvertimeEntryAttributes(
        companyId: $company->id, payPeriodId: $period->id, employeeId: $matchingEmployee->id,
        generation: $generation, sourceKey: 'wrong-status', rate: 'extra50', status: 'approved',
    ));
    PayrollReviewEntry::query()->create(projectedOvertimeEntryAttributes(
        companyId: $company->id, payPeriodId: $period->id, employeeId: $otherEmployee->id,
        generation: $generation, sourceKey: 'wrong-rate', rate: 'extra25',
    ));
    PayrollReviewEntry::query()->create(projectedOvertimeEntryAttributes(
        companyId: $company->id, payPeriodId: $period->id, employeeId: $matchingEmployee->id,
        generation: $generation, sourceKey: 'wrong-date', rate: 'extra50', workDate: '2026-07-21',
    ));

    $data = app(PayrollReviewProjection::class)->overtimeRows($period, $generation, [
        'search' => 'rate-50', 'status' => 'pending', 'date' => '2026-07-20', 'rate' => 'extra50',
    ], 1);

    expect($data['rows']->total())->toBe(1)
        ->and($data['rows']->sole()['review']->employee->external_id)->toBe('RATE-50')
        ->and($data['pendingCount'])->toBe(1);
});

function payrollReviewProjectionFixture(): array
{
    $company = Company::factory()->create();
    $profile = WorkScheduleProfile::factory()->forCompany($company)->create();
    WorkSchedule::factory()->forProfile($profile)->create([
        'day_of_week' => 1,
        'start_time' => '06:00',
        'end_time' => '14:00',
        'base_ordinary_hours' => 8,
    ]);
    $employee = Employee::factory()->forCompany($company)->create([
        'first_name' => 'María',
        'last_name' => 'Guardia',
        'external_id' => 'SEG-101',
    ]);
    app(EmployeeScheduleAssigner::class)->assign($employee, $profile, '2026-07-01', 'Jornada diurna');
    $period = PayPeriod::factory()->forCompany($company)->create([
        'start_date' => '2026-07-20',
        'end_date' => '2026-07-20',
        'status' => 'uploaded',
    ]);
    $file = UploadedFile::factory()->forCompany($company)->forPayPeriod($period)->create();

    foreach (['2026-07-20 06:00:00', '2026-07-20 14:30:00'] as $eventAt) {
        RawMark::factory()->forCompany($company)->forPayPeriod($period)
            ->forUploadedFile($file)->forEmployee($employee)->create([
                'event_at' => $eventAt,
                'status' => 'valid',
            ]);
    }

    $actor = User::factory()->forCompany($company)->create()->assignRole('company_admin');
    app(CurrentCompany::class)->set($company);

    return compact('company', 'employee', 'profile', 'period', 'actor');
}

function projectedOvertimeEntryAttributes(
    int $companyId,
    int $payPeriodId,
    int $employeeId,
    string $generation,
    string $sourceKey,
    string $rate,
    string $status = 'pending',
    string $workDate = '2026-07-20',
): array {
    $rateMinutes = ['ordinary' => 0, 'extra25' => 0, 'extra50' => 0, 'extra75' => 0, 'extra100' => 0];
    $rateMinutes[$rate] = 30;

    return [
        'company_id' => $companyId,
        'pay_period_id' => $payPeriodId,
        'employee_id' => $employeeId,
        'work_date' => $workDate,
        'type' => 'overtime_candidate',
        'status' => $status,
        'source_key' => $sourceKey,
        'source_fingerprint' => hash('sha256', $sourceKey),
        'generation' => $generation,
        'occurred_at' => "{$workDate} 14:00:00",
        'rate_ordinary_minutes' => $rateMinutes['ordinary'],
        'rate_extra25_minutes' => $rateMinutes['extra25'],
        'rate_extra50_minutes' => $rateMinutes['extra50'],
        'rate_extra75_minutes' => $rateMinutes['extra75'],
        'rate_extra100_minutes' => $rateMinutes['extra100'],
        'payload' => [
            'segment' => [
                'kind' => 'post_shift', 'key' => $sourceKey, 'fingerprint' => hash('sha256', $sourceKey),
                'start' => "{$workDate} 14:00:00", 'end' => "{$workDate} 14:30:00", 'minutes' => 30,
                'rate_minutes' => $rateMinutes,
            ],
            'analysis' => [
                'work_date' => $workDate, 'entry_at' => "{$workDate} 06:00:00", 'exit_at' => "{$workDate} 14:30:00",
                'payroll_policy_key' => 'schedule-overlap-v1', 'excluded_transfer_minutes' => 0,
            ],
            'occurrence' => ['scheduled_start' => "{$workDate} 06:00:00", 'scheduled_end' => "{$workDate} 14:00:00"],
            'resolution' => $status === 'pending' ? null : [
                'decision' => $status, 'reason' => 'Existing decision', 'resolution_kind' => null,
                'approved_minutes' => null, 'rejected_minutes' => null, 'decider_email' => null, 'created_at' => null,
            ],
        ],
    ];
}
