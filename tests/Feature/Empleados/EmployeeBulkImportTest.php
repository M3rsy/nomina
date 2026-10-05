<?php

use App\Livewire\Empleados\Index;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeImportBatch;
use App\Models\User;
use App\Models\WorkScheduleProfile;
use App\Services\Attendance\EmployeeScheduleAssigner;
use Database\Seeders\PermissionRoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile as LaravelUploadedFile;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
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

    $temporaryFile = tmpfile();
    fwrite($temporaryFile, $response->streamedContent());
    fflush($temporaryFile);
    $reader = IOFactory::createReader('Xlsx');
    $reader->setReadDataOnly(false);
    $spreadsheet = $reader->load(stream_get_meta_data($temporaryFile)['uri']);
    $sheet = $spreadsheet->getSheetByName('Empleados');

    expect($sheet->getCell('C2')->getValue())->toBe('2026-01-15')
        ->and($sheet->getCell('C2')->getDataType())->toBe(DataType::TYPE_STRING)
        ->and($sheet->getCell('H2')->getValue())->toBe('1990-05-20')
        ->and($sheet->getCell('H2')->getDataType())->toBe(DataType::TYPE_STRING)
        ->and($sheet->getStyle('C2')->getNumberFormat()->getFormatCode())->toBe('@')
        ->and($sheet->getStyle('H2')->getNumberFormat()->getFormatCode())->toBe('@')
        ->and($sheet->getStyle('C1001')->getNumberFormat()->getFormatCode())->toBe('@')
        ->and($sheet->getStyle('H1001')->getNumberFormat()->getFormatCode())->toBe('@');

    $spreadsheet->disconnectWorksheets();
    fclose($temporaryFile);
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

    $batch = EmployeeImportBatch::query()->latest('id')->firstOrFail();

    expect($first->payment_code)->toBe('PAY-001')
        ->and($first->first_name)->toBe('Ana')
        ->and($first->scheduleAssignments()->count())->toBe(1)
        ->and($second->payment_code)->toBe('PAY-002')
        ->and($second->scheduleAssignments()->count())->toBe(1)
        ->and($batch->status)->toBe(EmployeeImportBatch::COMPLETED)
        ->and($batch->total_rows)->toBe(2)
        ->and($batch->read_rows)->toBe(2)
        ->and($batch->imported_rows)->toBe(2)
        ->and($batch->actor_id)->toBe($admin->id)
        ->and($batch->original_filename)->toBe('empleados.xlsx');
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

    $batch = EmployeeImportBatch::query()->latest('id')->firstOrFail();

    expect(Employee::query()->where('first_name', 'Ana')->exists())->toBeFalse()
        ->and($batch->status)->toBe(EmployeeImportBatch::FAILED)
        ->and($batch->imported_rows)->toBe(0)
        ->and($batch->error_details)->not->toBeEmpty();
});

test('employee import groups repeated schedule errors with row numbers', function () {
    /** @var TestCase $this */
    $company = Company::factory()->create();
    $admin = User::factory()->forCompany($company)->create()->assignRole('company_admin');
    $this->actingAs($admin);

    Livewire::test(Index::class)
        ->set('importFile', employeeImportXlsx([
            ['IMP-001', 'PAY-001', '2026-01-15', 'Ana', 'Pérez'],
            ['IMP-002', 'PAY-002', '2026-01-15', 'Luis', 'Ramos'],
        ]))
        ->call('importEmployees')
        ->assertSee('2 filas: No existe una jornada general vigente para la fecha de contratación.')
        ->assertSee('(filas 2, 3)');

    expect(Employee::query()->count())->toBe(0)
        ->and(EmployeeImportBatch::query()->latest('id')->value('status'))->toBe(EmployeeImportBatch::FAILED);
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

test('employee import rejects retired employee codes and fails the whole batch', function () {
    /** @var TestCase $this */
    $company = Company::factory()->create();
    WorkScheduleProfile::factory()->forCompany($company)->create(['profile_key' => 'general']);
    $retiredEmployees = Employee::factory()->forCompany($company)->count(2)->sequence(
        ['external_id' => 'RETIRED-001'],
        ['external_id' => 'RETIRED-002'],
    )->create();
    $retiredEmployees->each->delete();
    $admin = User::factory()->forCompany($company)->create()->assignRole('company_admin');
    $this->actingAs($admin);

    Livewire::test(Index::class)
        ->set('importFile', employeeImportXlsx([
            ['RETIRED-001', 'PAY-001', '2026-01-15', 'Ana', 'Pérez'],
            ['RETIRED-002', 'PAY-002', '2026-01-16', 'Luis', 'Ramos'],
            ['NEW-001', 'PAY-003', '2026-01-17', 'Marta', 'López'],
        ]))
        ->call('importEmployees')
        ->assertHasErrors('importFile')
        ->assertSee('El código de empleado pertenece a un empleado retirado. Restaurá ese registro en lugar de crear un duplicado.')
        ->assertSee('(filas 2, 3)');

    $batch = EmployeeImportBatch::query()->latest('id')->firstOrFail();
    $message = 'El código de empleado pertenece a un empleado retirado. Restaurá ese registro en lugar de crear un duplicado.';

    expect(Employee::query()->where('external_id', 'NEW-001')->exists())->toBeFalse()
        ->and(Employee::withoutCompanyScope()->withTrashed()
            ->where('company_id', $company->id)
            ->whereIn('external_id', ['RETIRED-001', 'RETIRED-002'])
            ->count())->toBe(2)
        ->and($batch->status)->toBe(EmployeeImportBatch::FAILED)
        ->and($batch->imported_rows)->toBe(0)
        ->and($batch->error_details['rows.2.external_id'])->toBe([$message])
        ->and($batch->error_details['rows.3.external_id'])->toBe([$message]);
});

test('employee import converts a concurrent employee code race into a validation error', function () {
    /** @var TestCase $this */
    $company = Company::factory()->create();
    WorkScheduleProfile::factory()->forCompany($company)->create(['profile_key' => 'general']);
    $admin = User::factory()->forCompany($company)->create()->assignRole('company_admin');
    $this->actingAs($admin);

    $previousExceptionClass = 'PDOException';

    $this->mock(EmployeeScheduleAssigner::class)
        ->shouldReceive('createAndAssignGeneral')
        ->once()
        ->andThrow(new QueryException(
            'sqlite',
            'insert into employees (...) values (...)',
            [],
            new $previousExceptionClass(
                'SQLSTATE[23000]: Integrity constraint violation: 19 UNIQUE constraint failed: employees.company_id, employees.external_id',
                23000,
            ),
        ));

    Livewire::test(Index::class)
        ->set('importFile', employeeImportXlsx([
            ['RACE-001', 'PAY-001', '2026-01-15', 'Ana', 'Pérez'],
        ]))
        ->call('importEmployees')
        ->assertHasErrors('importFile')
        ->assertSee('Otro proceso creó uno de estos códigos de empleado durante la importación. Volvé a validar el archivo.');

    $batch = EmployeeImportBatch::query()->latest('id')->firstOrFail();

    expect(Employee::query()->count())->toBe(0)
        ->and($batch->status)->toBe(EmployeeImportBatch::FAILED)
        ->and($batch->error_summary[0]['message'])->toBe('Otro proceso creó uno de estos códigos de empleado durante la importación. Volvé a validar el archivo.');
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
