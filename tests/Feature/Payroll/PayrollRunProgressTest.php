<?php

use App\Jobs\ProcessPayrollRun;
use App\Livewire\Nomina\PayrollRunProgress;
use App\Livewire\Nomina\Revisar;
use App\Models\Company;
use App\Models\PayPeriod;
use App\Models\PayrollRun;
use App\Models\PayrollRunTelemetry;
use App\Models\User;
use App\Services\CurrentCompany;
use App\Services\Payroll\PayrollRunRequester;
use Database\Seeders\PermissionRoleSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(PermissionRoleSeeder::class);
    Queue::fake();
});

test('an authorized operator starts and follows payroll from a ready review', function () {
    $company = Company::factory()->create();
    $period = PayPeriod::factory()->forCompany($company)->create(['status' => 'ready']);
    $operator = User::factory()->forCompany($company)->create()->assignRole('company_admin');
    app(CurrentCompany::class)->set($company);
    $this->actingAs($operator);

    Livewire::test(Revisar::class, ['payPeriod' => $period])
        ->assertSee('Procesar nómina')
        ->call('startPayrollRun')
        ->assertSet('activePayrollRunId', fn (?int $id): bool => $id !== null)
        ->assertSet('payrollRunRequestKey', fn (string $key): bool => Str::isUuid($key))
        ->assertViewHas('isBlocked', true)
        ->assertSeeLivewire(PayrollRunProgress::class);

    $run = PayrollRun::withoutCompanyScope()->sole();
    expect($run->status)->toBe(PayrollRun::QUEUED)
        ->and($run->requested_by)->toBe($operator->id);
});

test('another authorized operator sees the active run for the same period', function () {
    $company = Company::factory()->create();
    $period = PayPeriod::factory()->forCompany($company)->create(['status' => 'ready']);
    $requester = User::factory()->forCompany($company)->create()->assignRole('company_admin');
    $observer = User::factory()->forCompany($company)->create()->assignRole('company_admin');
    app(CurrentCompany::class)->set($company);
    $this->actingAs($observer);
    $mountedBeforeReservation = Livewire::test(Revisar::class, ['payPeriod' => $period]);
    $run = app(PayrollRunRequester::class)->request($period, $requester, (string) Str::uuid());

    $mountedBeforeReservation->call('saveDraft');
    expect($period->fresh()->status)->toBe('ready');
    Livewire::test(Revisar::class, ['payPeriod' => $period])
        ->assertSet('activePayrollRunId', $run->id);
});

test('payroll progress and actions independently require payroll processing permission', function () {
    $company = Company::factory()->create();
    $period = PayPeriod::factory()->forCompany($company)->create(['status' => 'ready']);
    $requester = User::factory()->forCompany($company)->create()->assignRole('company_admin');
    $run = app(PayrollRunRequester::class)->request($period, $requester, (string) Str::uuid());
    $reviewer = User::factory()->forCompany($company)->create();
    $reviewer->givePermissionTo(['pay_periods.view', 'marks.manage']);
    app(CurrentCompany::class)->set($company);
    $this->actingAs($reviewer);

    Livewire::test(Revisar::class, ['payPeriod' => $period])
        ->assertOk()
        ->assertDontSeeLivewire(PayrollRunProgress::class)
        ->call('startPayrollRun')
        ->assertStatus(403);

    Livewire::test(PayrollRunProgress::class, [
        'payPeriod' => $period, 'runId' => $run->id,
    ])->assertStatus(403);
    $otherPeriod = PayPeriod::factory()->forCompany($company)->create(['status' => 'ready']);
    Livewire::test(Revisar::class, ['payPeriod' => $otherPeriod])
        ->call('startPayrollRun')
        ->assertStatus(403);

    $this->actingAs($requester);
    $component = Livewire::test(Revisar::class, ['payPeriod' => $otherPeriod]);
    $requester->syncRoles([]);
    $requester->syncPermissions(['marks.manage', 'payroll.process']);
    $this->actingAs($requester->fresh());
    $component->call('startPayrollRun')->assertStatus(403);
});

test('payroll progress reports status without a percentage and stops polling when terminal', function () {
    $company = Company::factory()->create();
    $period = PayPeriod::factory()->forCompany($company)->create(['status' => 'ready']);
    $operator = User::factory()->forCompany($company)->create()->assignRole('company_admin');
    $run = app(PayrollRunRequester::class)->request($period, $operator, (string) Str::uuid());
    app(CurrentCompany::class)->set($company);
    $this->actingAs($operator);

    $component = Livewire::test(PayrollRunProgress::class, [
        'payPeriod' => $period,
        'runId' => $run->id,
    ])
        ->assertSee('Nómina en cola')
        ->assertSeeHtml('wire:poll.3s="poll"')
        ->assertDontSee('%');

    $run->markProcessing();
    $component->call('poll')->assertSee('Procesando nómina');
    $period->update(['status' => 'processed']);
    $run->markCompleted();

    $component->call('poll')
        ->assertSee('Nómina procesada')
        ->assertDontSeeHtml('wire:poll.3s="poll"')
        ->assertDispatched('payroll-run-terminal', runId: $run->id);
});

test('a queued run that has not started warns that the worker is delayed', function () {
    Carbon::setTestNow('2026-08-19 10:00:00');
    $company = Company::factory()->create();
    $period = PayPeriod::factory()->forCompany($company)->create(['status' => 'ready']);
    $operator = User::factory()->forCompany($company)->create()->assignRole('company_admin');
    $run = app(PayrollRunRequester::class)->request($period, $operator, (string) Str::uuid());
    app(CurrentCompany::class)->set($company);
    $this->actingAs($operator);
    Carbon::setTestNow('2026-08-19 10:00:16');

    Livewire::test(PayrollRunProgress::class, ['payPeriod' => $period, 'runId' => $run->id])
        ->assertSet('delayed', true)
        ->assertSee('La cola está demorada')
        ->assertSeeHtml('wire:poll.3s="poll"');
});

test('a verified completed run redirects review to the existing payroll results', function () {
    $company = Company::factory()->create();
    $period = PayPeriod::factory()->forCompany($company)->create(['status' => 'ready']);
    $operator = User::factory()->forCompany($company)->create()->assignRole('company_admin');
    $run = app(PayrollRunRequester::class)->request($period, $operator, (string) Str::uuid());
    app(CurrentCompany::class)->set($company);
    $this->actingAs($operator);
    $component = Livewire::test(Revisar::class, ['payPeriod' => $period]);
    $run->markProcessing();
    $period->update(['status' => 'processed']);
    $run->markCompleted();

    $component->dispatch('payroll-run-terminal', runId: $run->id)
        ->assertRedirectToRoute('nomina.procesar', ['payPeriod' => $period]);
});

test('one progress retry action creates and follows the replacement run', function () {
    $company = Company::factory()->create();
    $period = PayPeriod::factory()->forCompany($company)->create(['status' => 'ready']);
    $operator = User::factory()->forCompany($company)->create()->assignRole('company_admin');
    $first = app(PayrollRunRequester::class)->request($period, $operator, (string) Str::uuid());
    app(CurrentCompany::class)->set($company);
    $this->actingAs($operator);
    $parent = Livewire::test(Revisar::class, ['payPeriod' => $period])
        ->set('search', 'Ana')
        ->set('status', 'valid');
    $first->markFailed('sensitive database credentials');
    PayrollRunTelemetry::create([
        'payroll_run_id' => $first->id,
        'event' => 'failed',
        'code' => 'attendance_review_blocked',
        'occurred_at' => now(),
    ]);

    Livewire::test(Revisar::class, ['payPeriod' => $period])
        ->assertSet('activePayrollRunId', $first->id)
        ->assertSee("Referencia #{$first->id}")
        ->assertSee('Intentar nuevamente');

    $progress = Livewire::test(PayrollRunProgress::class, ['payPeriod' => $period, 'runId' => $first->id])
        ->assertSee('No se pudo procesar la nómina.')
        ->assertSee('La revisión de asistencia tiene bloqueadores pendientes.')
        ->assertSee("Referencia #{$first->id}")
        ->assertSee('Intentar nuevamente')
        ->assertSeeHtml('wire:target="retry"')
        ->assertDontSee('sensitive database credentials')
        ->call('retry');

    $replacement = PayrollRun::withoutCompanyScope()->latest('id')->firstOrFail();

    $progress
        ->assertSet('runId', $replacement->id)
        ->assertSet('status', PayrollRun::QUEUED)
        ->assertDispatched(
            'payroll-run-retried',
            failedRunId: $first->id,
            runId: $replacement->id,
        )
        ->assertSee("Referencia #{$replacement->id}")
        ->assertDontSee('Intentar nuevamente');

    $parent->dispatch('payroll-run-terminal', runId: $first->id)
        ->assertSet('activePayrollRunId', $first->id)
        ->dispatch('payroll-run-retried', failedRunId: $first->id, runId: $replacement->id)
        ->assertSet('activePayrollRunId', $replacement->id)
        ->assertSet('search', 'Ana')
        ->assertSet('status', 'valid');

    expect(PayrollRun::withoutCompanyScope()->count())->toBe(2)
        ->and($replacement->request_key)
        ->not->toBe($first->request_key);
    expect(PayrollRunTelemetry::query()
        ->where('payroll_run_id', $replacement->id)
        ->where('event', 'queued')
        ->value('previous_run_id'))->toBe($first->id);

    Queue::assertPushed(
        ProcessPayrollRun::class,
        fn (ProcessPayrollRun $job): bool => $job->runId === $replacement->id,
    );

    $replacement->markFailed('worker stopped after accepting the retry');

    Livewire::test(PayrollRunProgress::class, ['payPeriod' => $period, 'runId' => $first->id])
        ->call('retry')
        ->assertSet('runId', $replacement->id)
        ->assertSet('status', PayrollRun::FAILED);

    expect(PayrollRun::withoutCompanyScope()->count())->toBe(2);
    Queue::assertPushed(ProcessPayrollRun::class, 2);
});

test('forged stale and cross-tenant terminal events cannot redirect or leak a run', function () {
    $company = Company::factory()->create();
    $period = PayPeriod::factory()->forCompany($company)->create(['status' => 'ready']);
    $operator = User::factory()->forCompany($company)->create()->assignRole('company_admin');
    $stale = app(PayrollRunRequester::class)->request($period, $operator, (string) Str::uuid());
    $stale->markProcessing();
    $stale->markCompleted();
    $active = app(PayrollRunRequester::class)->request($period, $operator, (string) Str::uuid());

    $foreignCompany = Company::factory()->create();
    $foreignPeriod = PayPeriod::factory()->forCompany($foreignCompany)->create(['status' => 'ready']);
    $foreignOperator = User::factory()->forCompany($foreignCompany)->create()->assignRole('company_admin');
    $foreign = app(PayrollRunRequester::class)->request($foreignPeriod, $foreignOperator, (string) Str::uuid());
    $foreign->markProcessing();
    $foreignPeriod->update(['status' => 'processed']);
    $foreign->markCompleted();

    $period->update(['status' => 'processed']);
    app(CurrentCompany::class)->set($company);
    $this->actingAs($operator);
    $parent = Livewire::test(Revisar::class, ['payPeriod' => $period])
        ->assertSet('activePayrollRunId', $active->id);

    $parent->dispatch('payroll-run-terminal', runId: $stale->id)
        ->assertNoRedirect()
        ->dispatch('payroll-run-terminal', runId: $foreign->id)
        ->assertNoRedirect()
        ->assertSet('activePayrollRunId', $active->id);

    Livewire::test(PayrollRunProgress::class, ['payPeriod' => $period, 'runId' => $foreign->id])
        ->assertSet('runId', null)
        ->assertDontSee("Referencia #{$foreign->id}");
});
