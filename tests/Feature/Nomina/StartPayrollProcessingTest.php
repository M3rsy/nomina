<?php

use App\Jobs\ProcessPayrollRun;
use App\Models\Company;
use App\Models\Employee;
use App\Models\PayPeriod;
use App\Models\PayrollRun;
use App\Models\User;
use App\Services\Attendance\HolidayCalendarContext;
use App\Services\Attendance\PayrollPeriodReviewSnapshot;
use App\Services\Attendance\PayrollPeriodSnapshotData;
use App\Services\Attendance\PayrollShiftEvaluationResolver;
use App\Services\Attendance\PayrollShiftReview;
use App\Services\Payroll\StartPayrollProcessing;
use Carbon\CarbonInterface;
use Database\Seeders\PermissionRoleSeeder;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->seed(PermissionRoleSeeder::class);
    Queue::fake();
});

test('atomically marks a reviewed period ready and reserves its payroll run', function () {
    $company = Company::factory()->create();
    $actor = User::factory()->forCompany($company)->create()->assignRole('company_admin');
    $period = PayPeriod::factory()->forCompany($company)->create(['status' => 'validating']);

    $run = app(StartPayrollProcessing::class)->start($period, $actor, (string) Str::uuid());

    expect($period->fresh()->status)->toBe('ready')
        ->and($run->status)->toBe(PayrollRun::QUEUED)
        ->and($run->pay_period_id)->toBe($period->id)
        ->and(PayrollRun::withoutCompanyScope()->count())->toBe(1);
    Queue::assertPushed(ProcessPayrollRun::class, fn ($job) => true);
});

test('replaying the same start request does not reserve a second run', function () {
    $company = Company::factory()->create();
    $actor = User::factory()->forCompany($company)->create()->assignRole('company_admin');
    $period = PayPeriod::factory()->forCompany($company)->create(['status' => 'draft']);
    $key = (string) Str::uuid();

    $first = app(StartPayrollProcessing::class)->start($period, $actor, $key);
    $replay = app(StartPayrollProcessing::class)->start($period->fresh(), $actor, $key);

    expect($replay->is($first))->toBeTrue()
        ->and(PayrollRun::withoutCompanyScope()->count())->toBe(1);
    Queue::assertPushed(ProcessPayrollRun::class, 1);
});

test('streams readiness blockers without materializing the full review graph', function () {
    $company = Company::factory()->create();
    $actor = User::factory()->forCompany($company)->create()->assignRole('company_admin');
    $period = PayPeriod::factory()->forCompany($company)->create([
        'start_date' => '2026-08-24',
        'end_date' => '2026-08-24',
        'status' => 'validating',
    ]);
    Employee::factory()->forCompany($company)->create([
        'hired_at' => '2026-08-01',
        'payment_code' => null,
    ]);

    $resolver = new class(app(PayrollShiftEvaluationResolver::class)) extends PayrollShiftEvaluationResolver
    {
        public int $reviewCalls = 0;

        public function __construct(private PayrollShiftEvaluationResolver $inner) {}

        public function review(
            PayPeriod $payPeriod,
            Employee $employee,
            CarbonInterface|string $workDate,
            ?HolidayCalendarContext $calendarContext = null,
            ?PayrollPeriodSnapshotData $snapshot = null,
        ): PayrollShiftReview {
            $this->reviewCalls++;

            return $this->inner->review($payPeriod, $employee, $workDate, $calendarContext, $snapshot);
        }
    };

    app()->forgetInstance(PayrollPeriodReviewSnapshot::class);
    app()->forgetInstance(StartPayrollProcessing::class);
    app()->instance(PayrollShiftEvaluationResolver::class, $resolver);

    try {
        expect(fn () => app(StartPayrollProcessing::class)->start($period, $actor, (string) Str::uuid()))
            ->toThrow(ValidationException::class, 'El período todavía tiene revisiones obligatorias pendientes.')
            ->and($resolver->reviewCalls)->toBe(1)
            ->and($period->fresh()->status)->toBe('validating');
    } finally {
        app()->forgetInstance(PayrollShiftEvaluationResolver::class);
        app()->forgetInstance(PayrollPeriodReviewSnapshot::class);
        app()->forgetInstance(StartPayrollProcessing::class);
    }
});
