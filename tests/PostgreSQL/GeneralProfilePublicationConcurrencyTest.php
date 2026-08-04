<?php

use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeScheduleAssignment;
use App\Models\PayPeriod;
use App\Models\User;
use App\Models\WorkScheduleProfile;
use App\Models\WorkScheduleProfilePublication;
use App\Services\Attendance\GeneralWorkSchedulePublisher;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;

function generalActivationFixture(): array
{
    $company = Company::factory()->create();
    $actor = User::factory()->create(['company_id' => $company->id]);
    $profile = WorkScheduleProfile::factory()->forCompany($company)->create(['profile_key' => 'general']);
    $employee = Employee::factory()->forCompany($company)->create();
    EmployeeScheduleAssignment::factory()->create([
        'company_id' => $company->id, 'employee_id' => $employee->id,
        'work_schedule_profile_id' => $profile->id, 'effective_from' => '2026-07-01',
    ]);
    PayPeriod::factory()->forCompany($company)->create([
        'start_date' => '2027-01-01', 'end_date' => '2027-01-15', 'status' => 'draft',
    ]);

    return [$company, $actor, $employee];
}

function activationProcess(array $payload): Process
{
    $process = new Process([PHP_BINARY, 'tests/Support/postgresql-worker.php', 'general-profile-activate', json_encode($payload)], base_path());
    $process->setInput(json_encode(['release' => 'start'])."\n");
    $process->start();

    return $process;
}

test('concurrent equivalent activation converges on one publication and assignment', function () {
    [$company, $actor, $employee] = generalActivationFixture();
    $payload = ['company_id' => $company->id, 'user_id' => $actor->id, 'requested_at' => '2026-08-02 10:00:00'];
    $workers = [activationProcess($payload), activationProcess($payload)];
    $results = collect($workers)->map(function (Process $process): array {
        $process->wait();

        return json_decode(collect(explode("\n", trim($process->getOutput())))->last(), true);
    });

    expect($results->pluck('checkpoint')->all())->toBe(['succeeded', 'succeeded'])
        ->and($results->pluck('publication_id')->unique())->toHaveCount(1)
        ->and(WorkScheduleProfilePublication::withoutCompanyScope()->where('company_id', $company->id)->where('payroll_policy_key', 'duration-first-v2')->count())->toBe(1)
        ->and(EmployeeScheduleAssignment::withoutCompanyScope()->where('employee_id', $employee->id)->count())->toBe(2);
});

test('foreign-key and overlap conflicts roll back public activation', function () {
    [$company, $actor] = generalActivationFixture();
    $missingActor = new User;
    $missingActor->id = PHP_INT_MAX;
    expect(fn () => app(GeneralWorkSchedulePublisher::class)->activate($company, $missingActor, 'Foreign actor', '2026-08-02 10:00:00'))
        ->toThrow(ValidationException::class);
    $overlap = WorkScheduleProfile::withoutEvents(fn () => WorkScheduleProfile::factory()->forCompany($company)->create(['profile_key' => 'general', 'version' => 2]));
    WorkScheduleProfilePublication::withoutCompanyScope()->create([
        'company_id' => $company->id, 'profile_key' => 'general', 'profile_id' => $overlap->id,
        'payroll_policy_key' => 'duration-first-v2', 'effective_from' => '2027-01-01',
        'definition_hash' => str_repeat('a', 64), 'request_key' => str_repeat('b', 64),
        'payload_hash' => str_repeat('c', 64), 'reason' => 'Overlap fixture', 'published_by' => $actor->id,
    ]);
    $before = WorkScheduleProfile::withoutCompanyScope()->where('company_id', $company->id)->count();
    expect(fn () => app(GeneralWorkSchedulePublisher::class)->activate($company, $actor, 'Overlap conflict', '2026-08-02 10:00:00'))
        ->toThrow(ValidationException::class)
        ->and(WorkScheduleProfile::withoutCompanyScope()->where('company_id', $company->id)->count())->toBe($before);
});
