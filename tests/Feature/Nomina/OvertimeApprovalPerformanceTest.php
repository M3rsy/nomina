<?php

use App\Livewire\Nomina\OvertimeReviewPanel;
use App\Livewire\Nomina\Revisar;
use App\Models\Company;
use App\Models\Employee;
use App\Models\OvertimeDecision;
use App\Models\PayPeriod;
use App\Models\RawMark;
use App\Models\UploadedFile;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Models\WorkScheduleProfile;
use App\Services\Attendance\AttendanceReviewQuery;
use App\Services\Attendance\EmployeeScheduleAssigner;
use App\Services\CurrentCompany;
use Carbon\CarbonImmutable;
use Database\Seeders\PermissionRoleSeeder;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

test('approves overtime within a bounded query budget for a representative period', function (): void {
    $this->seed(PermissionRoleSeeder::class);
    $start = CarbonImmutable::parse('2026-07-20');
    $end = $start->addDays(6);
    $company = Company::factory()->create();
    $profile = WorkScheduleProfile::factory()->forCompany($company)->create();

    foreach (range(0, 6) as $dayOfWeek) {
        WorkSchedule::factory()->forProfile($profile)->create([
            'day_of_week' => $dayOfWeek,
            'is_working_day' => true,
            'start_time' => '06:00',
            'end_time' => '14:00',
            'base_ordinary_hours' => 8,
        ]);
    }

    $period = PayPeriod::factory()->forCompany($company)->create([
        'start_date' => $start,
        'end_date' => $end,
        'status' => 'uploaded',
    ]);
    $file = UploadedFile::factory()->forCompany($company)->forPayPeriod($period)->create();
    $employees = collect();

    foreach (range(1, 4) as $number) {
        $employee = Employee::factory()->forCompany($company)->create([
            'external_id' => "PERF-{$number}",
        ]);
        app(EmployeeScheduleAssigner::class)->assign($employee, $profile, $start, 'Performance fixture');
        $employees->push($employee);

        for ($date = $start; $date->lte($end); $date = $date->addDay()) {
            foreach (['06:00:00', '14:30:00'] as $time) {
                RawMark::factory()->forCompany($company)->forPayPeriod($period)
                    ->forUploadedFile($file)->forEmployee($employee)->create([
                        'event_at' => "{$date->toDateString()} {$time}",
                        'status' => 'valid',
                    ]);
            }
        }
    }

    $employee = $employees->first();
    $candidate = app(AttendanceReviewQuery::class)
        ->forPeriod($period)
        ->first(fn ($review): bool => $review->employee->is($employee)
            && $review->analysis->workDate->isSameDay($start))
        ->analysis
        ->overtimeCandidates
        ->sole();
    $actor = User::factory()->forCompany($company)->create()->assignRole('company_admin');
    app(CurrentCompany::class)->set($company);
    $this->actingAs($actor);

    Livewire::test(OvertimeReviewPanel::class, ['payPeriod' => $period])
        ->call('openOvertimeDecision', $employee->id, $start->toDateString(), $candidate->key,
            OvertimeDecision::APPROVED, '14:00 → 14:30 · 30 min', '2026-07-20T14:00', '2026-07-20T14:30')
        ->assertSet('showOvertimeDecisionModal', true)
        ->set('overtimeDecisionReason', 'Representative approval')
        ->call('submitOvertimeDecision')
        ->assertDispatched('overtime-decision-submitted');

    $component = Livewire::test(Revisar::class, ['payPeriod' => $period]);

    DB::flushQueryLog();
    DB::enableQueryLog();

    try {
        $component
            ->call('saveOvertimeDecisionFromPanel', [
                'overtimeDecisionEmployeeId' => $employee->id,
                'overtimeDecisionWorkDate' => $start->toDateString(),
                'overtimeCandidateKey' => $candidate->key,
                'overtimeDecision' => OvertimeDecision::APPROVED,
                'overtimeDecisionReason' => 'Representative approval',
                'overtimeApprovedStartsAt' => '2026-07-20T14:00',
                'overtimeApprovedEndsAt' => '2026-07-20T14:30',
            ])
            ->assertHasNoErrors()
            ->assertDispatched('overtime-decision-recorded');
        $queryCount = count(DB::getQueryLog());
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }

    expect($queryCount)->toBeLessThanOrEqual(
        100,
        "Overtime approval executed {$queryCount} queries for 4 employees × 7 days.",
    );
});
