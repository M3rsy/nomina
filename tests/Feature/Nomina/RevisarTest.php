<?php

use App\Livewire\Nomina\Revisar;
use App\Models\AttendanceVariationAcknowledgement;
use App\Models\Company;
use App\Models\Employee;
use App\Models\PayPeriod;
use App\Models\RawMark;
use App\Models\UploadedFile;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Models\WorkScheduleProfile;
use App\Services\Attendance\EmployeeScheduleAssigner;
use App\Services\Attendance\PayrollShiftEvaluationResolver;
use App\Services\CurrentCompany;
use Carbon\Carbon;
use Database\Seeders\PermissionRoleSeeder;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(PermissionRoleSeeder::class);
});

test('super admin can render revisar page', function () {
    $company = Company::factory()->create();
    $payPeriod = PayPeriod::factory()->forCompany($company)->create();
    $superAdmin = User::factory()->create(['company_id' => null])->assignRole('super_admin');

    app(CurrentCompany::class)->set($company);

    $this->actingAs($superAdmin)
        ->get("/nomina/{$payPeriod->id}/revisar")
        ->assertOk();
});

test('super admin without active company cannot render revisar page', function () {
    $company = Company::factory()->create();
    $payPeriod = PayPeriod::factory()->forCompany($company)->create();
    $superAdmin = User::factory()->create(['company_id' => null])->assignRole('super_admin');

    app(CurrentCompany::class)->set(null);

    $this->actingAs($superAdmin)
        ->get("/nomina/{$payPeriod->id}/revisar")
        ->assertForbidden();
});

test('company admin can render revisar page of own company', function () {
    $company = Company::factory()->create();
    $payPeriod = PayPeriod::factory()->forCompany($company)->create();
    $admin = User::factory()->forCompany($company)->create()->assignRole('company_admin');

    $this->actingAs($admin)
        ->get("/nomina/{$payPeriod->id}/revisar")
        ->assertOk();
});

test('company admin cannot render revisar page of other company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $payPeriodB = PayPeriod::factory()->forCompany($companyB)->create();
    $adminA = User::factory()->forCompany($companyA)->create()->assignRole('company_admin');

    $this->actingAs($adminA)
        ->get("/nomina/{$payPeriodB->id}/revisar")
        ->assertForbidden();
});

test('component exposes status classes and labels for badges', function () {
    $company = Company::factory()->create();
    $payPeriod = PayPeriod::factory()->forCompany($company)->create();
    $admin = User::factory()->forCompany($company)->create()->assignRole('company_admin');
    $this->actingAs($admin);

    $component = Livewire::test(Revisar::class, ['payPeriod' => $payPeriod])->instance();

    expect($component->statusClass('valid'))->toBe('bg-green-100 text-green-800')
        ->and($component->statusClass('duplicate'))->toBe('bg-yellow-100 text-yellow-800')
        ->and($component->statusClass('out_of_period'))->toBe('bg-orange-100 text-orange-800')
        ->and($component->statusClass('unknown_employee'))->toBe('bg-red-100 text-red-800')
        ->and($component->statusClass('corrected'))->toBe('bg-blue-100 text-blue-800')
        ->and($component->statusClass('deleted'))->toBe('bg-gray-100 text-gray-800')
        ->and($component->statusClass('justified'))->toBe('bg-purple-100 text-purple-800')
        ->and($component->statusClass('pending'))->toBe('bg-gray-100 text-gray-400')
        ->and($component->statusLabel('valid'))->toBe('Válido')
        ->and($component->statusLabel('unknown_employee'))->toBe('Empleado desconocido');
});

test('table shows raw mark rows with badges', function () {
    $company = Company::factory()->create();
    $payPeriod = PayPeriod::factory()->forCompany($company)->create();
    $file = UploadedFile::factory()->forCompany($company)->forPayPeriod($payPeriod)->create();
    $employee = Employee::factory()->forCompany($company)->create();

    $validMark = RawMark::factory()->forCompany($company)->forPayPeriod($payPeriod)->forUploadedFile($file)->forEmployee($employee)->create([
        'status' => 'valid',
        'event_at' => Carbon::parse('2026-01-05 08:00:00'),
    ]);
    $unknownMark = RawMark::factory()->forCompany($company)->forPayPeriod($payPeriod)->forUploadedFile($file)->create([
        'status' => 'unknown_employee',
        'event_at' => Carbon::parse('2026-01-06 08:00:00'),
    ]);

    $admin = User::factory()->forCompany($company)->create()->assignRole('company_admin');
    $this->actingAs($admin);

    Livewire::test(Revisar::class, ['payPeriod' => $payPeriod])
        ->assertViewHas('records', function ($records) use ($validMark, $unknownMark) {
            $ids = $records->pluck('id')->toArray();

            return in_array($validMark->id, $ids, true)
                && in_array($unknownMark->id, $ids, true)
                && $records->count() === 2;
        })
        ->assertViewHas('summary', function ($summary) {
            return $summary['total'] === 2
                && $summary['valid'] === 1
                && $summary['unknown_employee'] === 1;
        });
});

test('search filter narrows raw marks by employee external id', function () {
    $company = Company::factory()->create();
    $payPeriod = PayPeriod::factory()->forCompany($company)->create();
    $file = UploadedFile::factory()->forCompany($company)->forPayPeriod($payPeriod)->create();

    $targetMark = RawMark::factory()->forCompany($company)->forPayPeriod($payPeriod)->forUploadedFile($file)->create([
        'employee_external_id' => '99999',
        'event_at' => Carbon::parse('2026-01-05 08:00:00'),
    ]);
    RawMark::factory()->forCompany($company)->forPayPeriod($payPeriod)->forUploadedFile($file)->create([
        'employee_external_id' => '11111',
        'event_at' => Carbon::parse('2026-01-06 08:00:00'),
    ]);

    $admin = User::factory()->forCompany($company)->create()->assignRole('company_admin');
    $this->actingAs($admin);

    Livewire::test(Revisar::class, ['payPeriod' => $payPeriod])
        ->set('search', '99999')
        ->assertViewHas('records', function ($records) use ($targetMark) {
            return $records->count() === 1 && $records->first()->id === $targetMark->id;
        });
});

test('status filter narrows raw marks by status', function () {
    $company = Company::factory()->create();
    $payPeriod = PayPeriod::factory()->forCompany($company)->create();
    $file = UploadedFile::factory()->forCompany($company)->forPayPeriod($payPeriod)->create();

    RawMark::factory()->forCompany($company)->forPayPeriod($payPeriod)->forUploadedFile($file)->create([
        'status' => 'valid',
        'event_at' => Carbon::parse('2026-01-05 08:00:00'),
    ]);
    RawMark::factory()->forCompany($company)->forPayPeriod($payPeriod)->forUploadedFile($file)->create([
        'status' => 'unknown_employee',
        'event_at' => Carbon::parse('2026-01-06 08:00:00'),
    ]);

    $admin = User::factory()->forCompany($company)->create()->assignRole('company_admin');
    $this->actingAs($admin);

    Livewire::test(Revisar::class, ['payPeriod' => $payPeriod])
        ->set('status', 'valid')
        ->assertViewHas('records', function ($records) {
            return $records->count() === 1 && $records->first()->status === 'valid';
        });
});

test('uploaded file filter narrows raw marks by source file', function () {
    $company = Company::factory()->create();
    $payPeriod = PayPeriod::factory()->forCompany($company)->create();
    $fileA = UploadedFile::factory()->forCompany($company)->forPayPeriod($payPeriod)->create();
    $fileB = UploadedFile::factory()->forCompany($company)->forPayPeriod($payPeriod)->create();

    $targetMark = RawMark::factory()->forCompany($company)->forPayPeriod($payPeriod)->forUploadedFile($fileA)->create([
        'event_at' => Carbon::parse('2026-01-05 08:00:00'),
    ]);
    RawMark::factory()->forCompany($company)->forPayPeriod($payPeriod)->forUploadedFile($fileB)->create([
        'event_at' => Carbon::parse('2026-01-06 08:00:00'),
    ]);

    $admin = User::factory()->forCompany($company)->create()->assignRole('company_admin');
    $this->actingAs($admin);

    Livewire::test(Revisar::class, ['payPeriod' => $payPeriod])
        ->set('uploaded_file_id', $fileA->id)
        ->assertViewHas('records', function ($records) use ($targetMark) {
            return $records->count() === 1 && $records->first()->id === $targetMark->id;
        });
});

test('variation transfer tail is auditable and pay neutral in payroll review', function () {
    $company = Company::factory()->create();
    $admin = User::factory()->forCompany($company)->create()->assignRole('company_admin');
    $profile = WorkScheduleProfile::factory()->forCompany($company)->create(['profile_key' => 'general']);
    foreach ([1, 2, 3] as $dayOfWeek) {
        WorkSchedule::factory()->forProfile($profile)->create([
            'day_of_week' => $dayOfWeek,
            'start_time' => '06:00',
            'end_time' => '14:00',
        ]);
    }
    $employee = Employee::factory()->forCompany($company)->create();
    app(EmployeeScheduleAssigner::class)->assign($employee, $profile, '2026-07-01', 'General schedule');
    $payPeriod = PayPeriod::factory()->forCompany($company)->create([
        'start_date' => '2026-07-20',
        'end_date' => '2026-07-22',
        'status' => 'uploaded',
    ]);
    foreach ([
        ['2026-07-20 07:00:00', '2026-07-20 15:00:00'],
        ['2026-07-21 06:00:00', '2026-07-21 16:25:00'],
        ['2026-07-22 06:00:00', '2026-07-22 16:31:00'],
    ] as $pair) {
        foreach ($pair as $eventAt) {
            RawMark::factory()->forCompany($company)->forPayPeriod($payPeriod)->forEmployee($employee)->create([
                'event_at' => $eventAt,
                'status' => 'valid',
            ]);
        }
    }
    DB::table('work_schedule_profile_publications')->where('profile_id', $profile->id)->update([
        'payroll_policy_key' => 'duration-first-v2',
        'published_by' => $admin->id,
    ]);
    app(CurrentCompany::class)->set($company);
    $this->actingAs($admin);

    $resolver = app(PayrollShiftEvaluationResolver::class);
    $before = $resolver->resolve($payPeriod, $employee, '2026-07-20');
    $variation = $resolver->review($payPeriod, $employee, '2026-07-20')->analysis->variations->sole();

    Livewire::test(Revisar::class, ['payPeriod' => $payPeriod])
        ->assertSee('Variación de entrada')
        ->assertSee('480 min ordinarios; no cambia el pago')
        ->assertSee('120 min detectados')
        ->assertSee('25 min de traslado excluidos')
        ->assertSee('151 min detectados')
        ->set('variationReason', 'Reviewed with employee')
        ->call('acknowledgeVariation', $employee->id, '2026-07-20', $variation->key, $variation->fingerprint)
        ->assertHasNoErrors()
        ->assertSee('Reviewed with employee')
        ->assertSee($admin->email);

    $acknowledgement = DB::table('attendance_variation_acknowledgements')->sole();
    $after = $resolver->resolve($payPeriod, $employee, '2026-07-20');

    expect($acknowledgement->acknowledged_by)->toBe($admin->id)
        ->and($acknowledgement->reason)->toBe('Reviewed with employee')
        ->and($after->payableRates)->toEqual($before->payableRates)
        ->and(fn () => AttendanceVariationAcknowledgement::findOrFail($acknowledgement->id)
            ->update(['reason' => 'Changed']))->toThrow(LogicException::class);
});
