<?php

use App\Livewire\Empleados\Index;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Models\WorkScheduleProfile;
use Database\Seeders\PermissionRoleSeeder;
use Illuminate\Http\UploadedFile as LaravelUploadedFile;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

uses()->beforeEach(function () {
    $this->seed(PermissionRoleSeeder::class);
});

function employeeImportXlsx(array $rows, ?array $headers = null): LaravelUploadedFile
{
    $spreadsheet = new Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->fromArray($headers ?? [
        'Código empleado *',
        'Clave *',
        'Fecha contratación *',
        'Nombre *',
        'Apellido *',
        'Identidad',
        'Sexo',
        'Fecha nacimiento',
        'Dirección',
        'Teléfono',
        'Cargo',
        'Salario esperado',
        'Notas',
    ], null, 'A1');

    foreach ($rows as $index => $row) {
        $sheet->fromArray($row, null, 'A'.($index + 2));
    }

    $path = tempnam(sys_get_temp_dir(), 'employee-import-').'.xlsx';
    (new Xlsx($spreadsheet))->save($path);
    $spreadsheet->disconnectWorksheets();

    return LaravelUploadedFile::fake()->createWithContent('empleados.xlsx', file_get_contents($path));
}

test('authorized employee creator can download the official Excel template', function () {
    /** @var TestCase $this */
    $company = Company::factory()->create();
    $admin = User::factory()->forCompany($company)->create()->assignRole('company_admin');

    $this->actingAs($admin);

    $response = $this->get(route('empleados.template'));

    $response->assertOk();
    $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    expect($response->streamedContent())->not->toBe('');
});

test('employee import creates employees and schedule assignments', function () {
    /** @var TestCase $this */
    $company = Company::factory()->create();
    WorkScheduleProfile::factory()->forCompany($company)->create(['profile_key' => 'general']);
    $admin = User::factory()->forCompany($company)->create()->assignRole('company_admin');
    $this->actingAs($admin);

    Livewire::test(Index::class)
        ->set('importFile', employeeImportXlsx([
            ['IMP-001', 'PAY-001', '2026-01-15', 'Ana', 'Pérez', '123456789', 'F', '1990-05-20', 'Av. Central', '8888-8888', 'Administradora', '25000.00', 'Alta inicial'],
            ['IMP-002', 'PAY-002', '2026-01-16', 'Luis', 'Ramos', '987654321', 'M', '1991-06-21', 'Calle Norte', '7777-7777', 'Operador', '18000.00', null],
        ]))
        ->call('importEmployees')
        ->assertHasNoErrors()
        ->assertSet('importSummary.count', 2);

    $first = Employee::query()->where('external_id', 'IMP-001')->firstOrFail();
    $second = Employee::query()->where('external_id', 'IMP-002')->firstOrFail();

    expect($first->payment_code)->toBe('PAY-001')
        ->and($first->first_name)->toBe('Ana')
        ->and($first->scheduleAssignments()->count())->toBe(1)
        ->and($second->payment_code)->toBe('PAY-002')
        ->and($second->scheduleAssignments()->count())->toBe(1);
});

test('employee import rejects missing required fields without creating employees', function () {
    /** @var TestCase $this */
    $company = Company::factory()->create();
    WorkScheduleProfile::factory()->forCompany($company)->create(['profile_key' => 'general']);
    $admin = User::factory()->forCompany($company)->create()->assignRole('company_admin');
    $this->actingAs($admin);

    Livewire::test(Index::class)
        ->set('importFile', employeeImportXlsx([
            ['', '', '', 'Ana', 'Pérez'],
        ]))
        ->call('importEmployees')
        ->assertHasErrors('importFile')
        ->assertSee('La clave es obligatoria.');

    expect(Employee::query()->where('first_name', 'Ana')->exists())->toBeFalse();
});

test('employee import rejects duplicate codes safely', function () {
    /** @var TestCase $this */
    $company = Company::factory()->create();
    WorkScheduleProfile::factory()->forCompany($company)->create(['profile_key' => 'general']);
    Employee::factory()->forCompany($company)->create(['external_id' => 'EXISTING']);
    $admin = User::factory()->forCompany($company)->create()->assignRole('company_admin');
    $this->actingAs($admin);

    Livewire::test(Index::class)
        ->set('importFile', employeeImportXlsx([
            ['EXISTING', 'PAY-001', '2026-01-15', 'Ana', 'Pérez'],
            ['NEW-001', 'PAY-002', '2026-01-16', 'Luis', 'Ramos'],
            ['NEW-001', 'PAY-003', '2026-01-17', 'Marta', 'López'],
        ]))
        ->call('importEmployees')
        ->assertHasErrors('importFile')
        ->assertSee('El código de empleado ya existe en esta empresa.')
        ->assertSee('El código de empleado está repetido dentro del archivo.');

    expect(Employee::query()->whereIn('external_id', ['NEW-001'])->exists())->toBeFalse();
});

test('employee import rejects duplicate normalized headers without creating employees', function () {
    /** @var TestCase $this */
    $company = Company::factory()->create();
    WorkScheduleProfile::factory()->forCompany($company)->create(['profile_key' => 'general']);
    $admin = User::factory()->forCompany($company)->create()->assignRole('company_admin');
    $this->actingAs($admin);

    Livewire::test(Index::class)
        ->set('importFile', employeeImportXlsx([
            ['IMP-001', 'PAY-001', '2026-01-15', 'Ana', 'Ana duplicada', 'Pérez'],
        ], [
            'Código empleado *',
            'Clave *',
            'Fecha contratación *',
            'Nombre *',
            'Nombre',
            'Apellido *',
            'Identidad',
            'Sexo',
            'Fecha nacimiento',
            'Dirección',
            'Teléfono',
            'Cargo',
            'Salario esperado',
            'Notas',
        ]))
        ->call('importEmployees')
        ->assertHasErrors('importFile')
        ->assertSee('La columna «Nombre» está repetida.');

    expect(Employee::query()->where('external_id', 'IMP-001')->exists())->toBeFalse();
});

test('employee import rejects invalid calendar dates without creating employees', function () {
    /** @var TestCase $this */
    $company = Company::factory()->create();
    WorkScheduleProfile::factory()->forCompany($company)->create(['profile_key' => 'general']);
    $admin = User::factory()->forCompany($company)->create()->assignRole('company_admin');
    $this->actingAs($admin);

    Livewire::test(Index::class)
        ->set('importFile', employeeImportXlsx([
            ['IMP-001', 'PAY-001', '2026-02-31', 'Ana', 'Pérez'],
        ]))
        ->call('importEmployees')
        ->assertHasErrors('importFile')
        ->assertSee('La fecha de contratación no es válida.');

    expect(Employee::query()->where('external_id', 'IMP-001')->exists())->toBeFalse();
});

test('employee import rejects more than the maximum data rows without creating employees', function () {
    /** @var TestCase $this */
    $company = Company::factory()->create();
    $admin = User::factory()->forCompany($company)->create()->assignRole('company_admin');
    $this->actingAs($admin);

    $rows = array_map(
        fn (int $index): array => ["IMP-{$index}", "PAY-{$index}", '2026-01-15', 'Ana', 'Pérez'],
        range(1, 1001),
    );

    Livewire::test(Index::class)
        ->set('importFile', employeeImportXlsx($rows))
        ->call('importEmployees')
        ->assertHasErrors('importFile')
        ->assertSee('El archivo no puede contener más de 1000 filas de datos.');

    expect(Employee::query()->count())->toBe(0);
});

test('employee import rejects more than the official template columns without creating employees', function () {
    /** @var TestCase $this */
    $company = Company::factory()->create();
    $admin = User::factory()->forCompany($company)->create()->assignRole('company_admin');
    $this->actingAs($admin);

    $headers = [
        'Código empleado *',
        'Clave *',
        'Fecha contratación *',
        'Nombre *',
        'Apellido *',
        'Identidad',
        'Sexo',
        'Fecha nacimiento',
        'Dirección',
        'Teléfono',
        'Cargo',
        'Salario esperado',
        'Notas',
        'Columna adicional',
    ];

    Livewire::test(Index::class)
        ->set('importFile', employeeImportXlsx([
            ['IMP-001', 'PAY-001', '2026-01-15', 'Ana', 'Pérez'],
        ], $headers))
        ->call('importEmployees')
        ->assertHasErrors('importFile')
        ->assertSee('El archivo no puede contener más de 13 columnas.');

    expect(Employee::query()->where('external_id', 'IMP-001')->exists())->toBeFalse();
});

test('unauthorized users cannot download or import employees', function () {
    /** @var TestCase $this */
    $company = Company::factory()->create();
    $user = User::factory()->forCompany($company)->create();
    $user->givePermissionTo('employees.view');
    $this->actingAs($user);

    $this->get(route('empleados.template'))->assertForbidden();

    Livewire::test(Index::class)
        ->set('importFile', employeeImportXlsx([
            ['IMP-001', 'PAY-001', '2026-01-15', 'Ana', 'Pérez'],
        ]))
        ->call('importEmployees')
        ->assertForbidden();
});
