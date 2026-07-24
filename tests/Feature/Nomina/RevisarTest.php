<?php

use App\Livewire\Nomina\Revisar;
use App\Models\Company;
use App\Models\Employee;
use App\Models\PayPeriod;
use App\Models\RawMark;
use App\Models\UploadedFile;
use App\Models\User;
use App\Services\CurrentCompany;
use Carbon\Carbon;
use Database\Seeders\PermissionRoleSeeder;
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
