<?php

use App\Models\Company;
use App\Models\Employee;
use App\Models\PayPeriod;
use App\Models\PayrollResult;
use App\Services\Payroll\PayrollExcelExporter;
use App\Services\Payroll\PayrollStubExporter;
use Carbon\Carbon;
use Database\Seeders\PermissionRoleSeeder;
use PhpOffice\PhpSpreadsheet\IOFactory;

beforeEach(function () {
    $this->seed(PermissionRoleSeeder::class);
});

function reportingSnapshot(string $externalId, string $name, string $workDate, int $worked, int $ordinary, int $extra25 = 0): array
{
    return [
        'schema_version' => 2,
        'work_date' => $workDate,
        'employee' => ['external_id' => $externalId, 'name' => $name],
        'rules_version' => 'duration-first-v2',
        'attendance' => [
            'marks' => [],
            'entry_at' => null,
            'exit_at' => null,
            'worked_minutes' => $worked,
            'detected_overtime_minutes' => $extra25,
            'approved_overtime_minutes' => $extra25,
            'excluded_transfer_minutes' => 0,
        ],
        'payable_minutes' => [
            'ordinary' => $ordinary,
            'extra25' => $extra25,
            'extra50' => 0,
            'extra75' => 0,
            'extra100' => 0,
        ],
        'shortfalls' => [],
        'overtime' => [],
        'variations' => [],
    ];
}

test('PayrollExcelExporter produces expected sheet structure', function () {
    $company = Company::factory()->create();
    $payPeriod = PayPeriod::factory()->forCompany($company)->create([
        'start_date' => '2024-01-20',
        'end_date' => '2024-01-27',
        'status' => 'processed',
    ]);
    $employee = Employee::factory()->forCompany($company)->create([
        'external_id' => '1',
        'first_name' => 'Juan',
        'last_name' => 'Perez',
    ]);

    PayrollResult::factory()->forCompany($company)->forPayPeriod($payPeriod)->forEmployee($employee)->create([
        'date' => '2024-01-22',
        'entry_at' => Carbon::parse('2024-01-22 08:00:00'),
        'exit_at' => Carbon::parse('2024-01-22 17:00:00'),
        'worked_hours' => 9.0,
        'ordinary_hours' => 8.0,
        'worked_minutes' => 540,
        'ordinary_minutes' => 480,
        'extra_25_hours' => 0.5,
        'extra_50_hours' => 0,
        'extra_75_hours' => 0,
        'extra_100_hours' => 0,
        'extra_25_minutes' => 30,
    ]);

    $exporter = new PayrollExcelExporter;
    $path = $exporter->export($payPeriod);

    expect($path)->toBeFile()
        ->and($exporter->filename($payPeriod))->toBe('Asistencia 20240120 hasta 20240127.xlsx');

    $reader = IOFactory::createReaderForFile($path);
    $reader->setReadDataOnly(true);
    $spreadsheet = $reader->load($path);
    $sheet = $spreadsheet->getActiveSheet();
    $data = $sheet->toArray(null, true, false, false);

    // Reference layout: row 1 empty, row 2 title (merged), row 3 week label,
    // row 4 empty, row 5 header (bold/centered), row 6 onwards data rows.
    expect($sheet->getTitle())->toBe('Hoja1')
        ->and($data[1][0])->toMatch('/^REPORTE DEL/')
        ->and($data[2][0])->toMatch('/^SEMANA/')
        ->and(array_slice($data[4], 0, 10))->toBe(['Codigo', 'NOMBRE', 'Entrada', 'Salida', 'Cantidad Horas', 'Horas Ordinarias', 'Horas Ext 25%', 'Horas Ext 50%', 'Horas Ext 75%', 'Horas Ext 100%'])
        ->and($data[5])->toContain(1, 'Juan Perez')
        ->and($data[5][2])->toContain('2024-01-22')
        ->and($data[5][4])->toBe(9.0)
        ->and($data[5][5])->toBe(8.0)
        ->and($data[5][6])->toBe(0.5)
        ->and($data[5][7])->toBe(0.0)
        ->and($data[5][8])->toBe(0.0)
        ->and($data[5][9])->toBe(0.0);
});

test('PayrollExcelExporter keeps employee identity snapshot after employee changes', function () {
    $company = Company::factory()->create();
    $payPeriod = PayPeriod::factory()->forCompany($company)->create([
        'start_date' => '2024-01-20',
        'end_date' => '2024-01-27',
        'status' => 'processed',
    ]);
    $employee = Employee::factory()->forCompany($company)->create([
        'external_id' => '1',
        'first_name' => 'Juan',
        'last_name' => 'Perez',
    ]);

    PayrollResult::factory()->forCompany($company)->forPayPeriod($payPeriod)->forEmployee($employee)->create([
        'date' => '2024-01-22',
        'employee_external_id' => '1',
        'employee_name' => 'Juan Perez',
        'worked_hours' => 9,
        'ordinary_hours' => 8,
        'worked_minutes' => 540,
        'ordinary_minutes' => 480,
        'extra_25_minutes' => 30,
        'extra_25_hours' => 0.5,
    ]);

    $employee->update([
        'external_id' => '2',
        'first_name' => 'Renamed',
        'last_name' => 'Worker',
    ]);

    $path = (new PayrollExcelExporter)->export($payPeriod);
    $data = IOFactory::load($path)->getActiveSheet()->toArray(null, true, false, false);

    expect($data[5][0])->toBe(1)
        ->and($data[5][1])->toBe('Juan Perez');

    unlink($path);
});

test('PayrollStubExporter produces expected sheet structure', function () {
    $company = Company::factory()->create();
    $payPeriod = PayPeriod::factory()->forCompany($company)->create([
        'start_date' => '2024-01-20',
        'end_date' => '2024-01-27',
        'status' => 'exported',
    ]);
    $employee = Employee::factory()->forCompany($company)->create([
        'external_id' => '1',
        'first_name' => 'Juan',
        'last_name' => 'Perez',
    ]);

    PayrollResult::factory()->forCompany($company)->forPayPeriod($payPeriod)->forEmployee($employee)->create([
        'date' => '2024-01-22',
        'entry_at' => Carbon::parse('2024-01-22 08:00:00'),
        'exit_at' => Carbon::parse('2024-01-22 17:00:00'),
        'worked_hours' => 9.0,
        'ordinary_hours' => 8.0,
        'worked_minutes' => 540,
        'ordinary_minutes' => 480,
        'extra_25_hours' => 0.5,
        'extra_25_minutes' => 30,
    ]);

    $exporter = new PayrollStubExporter;
    $path = $exporter->export($payPeriod, $employee);

    expect($path)->toBeFile()
        ->and($exporter->filename($payPeriod, $employee))->toBe("Comprobante {$employee->external_id} {$payPeriod->slug}.xlsx");

    $reader = IOFactory::createReaderForFile($path);
    $reader->setReadDataOnly(true);
    $spreadsheet = $reader->load($path);
    $sheet = $spreadsheet->getActiveSheet();
    $data = $sheet->toArray(null, true, false, false);

    expect($sheet->getTitle())->toBe('Comprobante')
        ->and($data[0][0])->toBe('Comprobante de nómina')
        ->and($data[7])->toContain('Codigo', 'NOMBRE', 'Entrada', 'Salida')
        ->and($data[8])->toContain('Juan Perez', 1)
        ->and($data[8][6])->toBe(0.5)
        ->and($data[9][6])->toBe(0.5);
});

test('PayrollStubExporter keeps employee identity snapshot after employee changes', function () {
    $company = Company::factory()->create();
    $payPeriod = PayPeriod::factory()->forCompany($company)->create([
        'start_date' => '2024-01-20',
        'end_date' => '2024-01-27',
        'status' => 'exported',
    ]);
    $employee = Employee::factory()->forCompany($company)->create([
        'external_id' => '1',
        'first_name' => 'Juan',
        'last_name' => 'Perez',
    ]);

    PayrollResult::factory()->forCompany($company)->forPayPeriod($payPeriod)->forEmployee($employee)->create([
        'date' => '2024-01-22',
        'employee_external_id' => '1',
        'employee_name' => 'Juan Perez',
        'worked_hours' => 9,
        'ordinary_hours' => 8,
        'worked_minutes' => 540,
        'ordinary_minutes' => 480,
        'extra_25_minutes' => 30,
        'extra_25_hours' => 0.5,
    ]);

    $employee->update([
        'external_id' => '2',
        'first_name' => 'Renamed',
        'last_name' => 'Worker',
    ]);

    $exporter = new PayrollStubExporter;
    $path = $exporter->export($payPeriod, $employee);

    expect($exporter->filename($payPeriod, $employee))->toBe("Comprobante 1 {$payPeriod->slug}.xlsx");

    $data = IOFactory::load($path)->getActiveSheet()->toArray(null, true, false, false);

    expect($data[1][1])->toBe('Juan Perez')
        ->and($data[2][1])->toBe(1)
        ->and($data[8][0])->toBe(1)
        ->and($data[8][1])->toBe('Juan Perez');

    unlink($path);
});

test('payroll exports label legacy rows and leave unavailable snapshot facts blank', function () {
    $company = Company::factory()->create();
    $payPeriod = PayPeriod::factory()->forCompany($company)->create([
        'start_date' => '2024-01-20',
        'end_date' => '2024-01-27',
        'status' => 'exported',
    ]);
    $employee = Employee::factory()->forCompany($company)->create();

    PayrollResult::factory()->forCompany($company)->forPayPeriod($payPeriod)->forEmployee($employee)->create([
        'date' => '2024-01-22',
        'worked_minutes' => 480,
        'ordinary_minutes' => 480,
        'rules_version' => 'schedule-overlap-v1',
        'day_snapshot' => null,
    ]);

    $globalPath = (new PayrollExcelExporter)->export($payPeriod);
    $global = IOFactory::load($globalPath)->getActiveSheet();

    expect($global->getCell('L6')->getValue())->toBe('LEGACY')
        ->and($global->getCell('N6')->getValue())->toBeNull()
        ->and($global->getCell('O6')->getValue())->toBeNull()
        ->and($global->getCell('U6')->getValue())->toBeNull()
        ->and($global->getCell('AC6')->getValue())->toBeNull()
        ->and($global->getCell('AD6')->getValue())->toBe('schedule-overlap-v1');

    $stubPath = (new PayrollStubExporter)->export($payPeriod, $employee);
    $stub = IOFactory::load($stubPath)->getActiveSheet();

    expect($stub->getCell('N9')->getValue())->toBe('LEGACY')
        ->and($stub->getCell('P9')->getValue())->toBeNull()
        ->and($stub->getCell('Q9')->getValue())->toBeNull()
        ->and($stub->getCell('X9')->getValue())->toBeNull()
        ->and($stub->getCell('AF9')->getValue())->toBeNull()
        ->and($stub->getCell('AG9')->getValue())->toBe('schedule-overlap-v1');

    unlink($globalPath);
    unlink($stubPath);
});

test('legacy exports recover null identity from a soft-deleted employee', function () {
    $company = Company::factory()->create();
    $payPeriod = PayPeriod::factory()->forCompany($company)->create(['status' => 'exported']);
    $employee = Employee::factory()->forCompany($company)->create([
        'external_id' => 'LEGACY-7', 'first_name' => 'Legacy', 'last_name' => 'Worker',
    ]);
    PayrollResult::factory()->forCompany($company)->forPayPeriod($payPeriod)->forEmployee($employee)->create([
        'employee_external_id' => null, 'employee_name' => null, 'day_snapshot' => null,
    ]);
    $employee->delete();

    $globalPath = (new PayrollExcelExporter)->export($payPeriod);
    $stubPath = (new PayrollStubExporter)->export($payPeriod, $employee);
    $global = IOFactory::load($globalPath)->getActiveSheet();
    $stub = IOFactory::load($stubPath)->getActiveSheet();

    expect($global->getCell('A6')->getValue())->toBe('LEGACY-7')
        ->and($global->getCell('B6')->getValue())->toBe('Legacy Worker')
        ->and($stub->getCell('B2')->getValue())->toBe('Legacy Worker')
        ->and($stub->getCell('B3')->getValue())->toBe('LEGACY-7')
        ->and($stub->getCell('A9')->getValue())->toBe('LEGACY-7')
        ->and($stub->getCell('B9')->getValue())->toBe('Legacy Worker');

    unlink($globalPath);
    unlink($stubPath);
});

test('global and employee exports expose canonical immutable snapshot columns', function () {
    $company = Company::factory()->create();
    $payPeriod = PayPeriod::factory()->forCompany($company)->create([
        'start_date' => '2026-07-06',
        'end_date' => '2026-07-12',
        'status' => 'exported',
    ]);
    $employee = Employee::factory()->forCompany($company)->create();
    $snapshot = [
        'schema_version' => 2,
        'work_date' => '2026-07-07',
        'employee' => ['external_id' => 'SNAP-7', 'name' => 'Frozen Employee'],
        'rules_version' => 'duration-first-v2',
        'attendance' => [
            'marks' => [[
                'id' => 91,
                'event_at' => '2026-07-07 06:00:00',
                'status' => 'valid',
                'source' => 'clock',
                'revisions' => [['changed_at' => '2026-07-07 06:01:00']],
            ]],
            'entry_at' => '2026-07-07 06:00:00',
            'exit_at' => '2026-07-07 16:25:00',
            'worked_minutes' => 625,
            'detected_overtime_minutes' => 120,
            'approved_overtime_minutes' => 60,
            'excluded_transfer_minutes' => 25,
        ],
        'payable_minutes' => [
            'ordinary' => 480,
            'extra25' => 30,
            'extra50' => 30,
            'extra75' => 0,
            'extra100' => 0,
        ],
        'shortfalls' => [[
            'state' => 'rejected',
            'reason' => 'Unpaid audited shortfall',
            'fact' => ['minutes' => 60],
            'decision' => ['id' => 11, 'decision' => 'rejected'],
        ]],
        'overtime' => [[
            'candidate' => ['starts_at' => '2026-07-07 14:00:00', 'ends_at' => '2026-07-07 16:00:00', 'minutes' => 120],
            'decision' => [
                'approved_starts_at' => '2026-07-07 14:00:00',
                'approved_ends_at' => '2026-07-07 15:00:00',
                'approved_minutes' => 60,
                'rejected_after_starts_at' => '2026-07-07 15:00:00',
                'rejected_after_ends_at' => '2026-07-07 16:00:00',
                'rejected_minutes' => 60,
            ],
        ]],
        'variations' => [[
            'kind' => 'schedule_entry',
            'entry_at' => '2026-07-07 07:00:00',
            'acknowledgement' => ['reason' => 'Reviewed', 'acknowledged_by' => 8],
        ]],
    ];

    PayrollResult::factory()->forCompany($company)->forPayPeriod($payPeriod)->forEmployee($employee)->create([
        'date' => '2026-07-08',
        'employee_external_id' => 'MUTABLE-7',
        'employee_name' => 'Mutable Employee',
        'worked_minutes' => 0,
        'ordinary_minutes' => 0,
        'day_snapshot' => $snapshot,
    ]);

    $globalPath = (new PayrollExcelExporter)->export($payPeriod);
    $global = IOFactory::load($globalPath)->getActiveSheet();

    expect($global->rangeToArray('K5:AD5')[0])->toBe([
        'Fecha laboral', 'Estado de fila', 'Minutos observados', 'Marcas observadas', 'Revisiones de marcas',
        'Minutos ordinarios', 'Minutos Ext 25%', 'Minutos Ext 50%', 'Minutos Ext 75%', 'Minutos Ext 100%',
        'Déficit minutos', 'Déficit estado', 'Déficit motivo', 'Hora extra detectada', 'Hora extra aprobada',
        'Hora extra rechazada', 'Variación', 'Reconocimiento de variación', 'Transferencia excluida', 'Versión de reglas',
    ])->and($global->rangeToArray('A6:AD6')[0])->toMatchArray([
        0 => 'SNAP-7', 1 => 'Frozen Employee', 10 => '2026-07-07', 11 => 'CURRENT', 12 => 625,
        15 => 480, 16 => 30, 17 => 30, 18 => 0, 19 => 0, 20 => 60, 21 => 'rejected',
        22 => 'Unpaid audited shortfall', 28 => 25, 29 => 'duration-first-v2',
    ])->and($global->getCell('N6')->getValue())->toContain('2026-07-07 06:00:00')
        ->and($global->getCell('O6')->getValue())->toContain('2026-07-07 06:01:00')
        ->and($global->getCell('X6')->getValue())->toContain('2026-07-07 14:00:00')
        ->and($global->getCell('Y6')->getValue())->toContain('2026-07-07 15:00:00')
        ->and($global->getCell('Z6')->getValue())->toContain('2026-07-07 16:00:00')
        ->and($global->getCell('AA6')->getValue())->toContain('schedule_entry')
        ->and($global->getCell('AB6')->getValue())->toContain('Reviewed');

    $stubPath = (new PayrollStubExporter)->export($payPeriod, $employee);
    $stub = IOFactory::load($stubPath)->getActiveSheet();

    expect($stub->rangeToArray('M8:AG8')[0])->toBe([
        'Fecha laboral', 'Estado de fila', 'Minutos observados', 'Marcas observadas', 'Revisiones de marcas',
        'Minutos ordinarios', 'Minutos Ext 25%', 'Minutos Ext 50%', 'Minutos Ext 75%', 'Minutos Ext 100%',
        'Déficit minutos', 'Déficit estado', 'Déficit motivo', 'Hora extra detectada', 'Hora extra aprobada',
        'Hora extra rechazada', 'Variación', 'Reconocimiento de variación', 'Minutos extra aprobados',
        'Transferencia excluida', 'Versión de reglas',
    ])->and($stub->getCell('B2')->getValue())->toBe('Frozen Employee')
        ->and($stub->getCell('B3')->getValue())->toBe('SNAP-7')
        ->and((new PayrollStubExporter)->filename($payPeriod, $employee))->toBe("Comprobante SNAP-7 {$payPeriod->slug}.xlsx")
        ->and($stub->rangeToArray('M9:AG9')[0])->toMatchArray([
            0 => '2026-07-07', 1 => 'CURRENT', 2 => 625, 5 => 480, 6 => 30, 7 => 30,
            8 => 0, 9 => 0, 10 => 60, 11 => 'rejected', 12 => 'Unpaid audited shortfall',
            18 => 60, 19 => 25, 20 => 'duration-first-v2',
        ])->and($stub->getCell('P9')->getValue())->toContain('2026-07-07 06:00:00')
        ->and($stub->getCell('Q9')->getValue())->toContain('2026-07-07 06:01:00')
        ->and($stub->getCell('Z9')->getValue())->toContain('2026-07-07 14:00:00')
        ->and($stub->getCell('AA9')->getValue())->toContain('2026-07-07 15:00:00')
        ->and($stub->getCell('AB9')->getValue())->toContain('2026-07-07 16:00:00')
        ->and($stub->getCell('AC9')->getValue())->toContain('schedule_entry')
        ->and($stub->getCell('AD9')->getValue())->toContain('Reviewed');

    unlink($globalPath);
    unlink($stubPath);
});

test('payroll exports provide employee subtotals and document grand totals', function () {
    $company = Company::factory()->create();
    $payPeriod = PayPeriod::factory()->forCompany($company)->create([
        'start_date' => '2026-07-06',
        'end_date' => '2026-07-12',
        'status' => 'exported',
    ]);
    $employeeA = Employee::factory()->forCompany($company)->create();
    $employeeB = Employee::factory()->forCompany($company)->create();

    PayrollResult::factory()->forCompany($company)->forPayPeriod($payPeriod)->forEmployee($employeeA)->create([
        'date' => '2026-07-06',
        'day_snapshot' => reportingSnapshot('A-1', 'Employee A', '2026-07-06', 60, 60),
    ]);
    PayrollResult::factory()->forCompany($company)->forPayPeriod($payPeriod)->forEmployee($employeeB)->create([
        'date' => '2026-07-07',
        'day_snapshot' => reportingSnapshot('B-1', 'Employee B', '2026-07-07', 150, 120, 30),
    ]);

    $globalPath = (new PayrollExcelExporter)->export($payPeriod);
    $global = IOFactory::load($globalPath)->getActiveSheet();

    expect($global->getCell('B7')->getValue())->toBe('EMPLOYEE SUBTOTAL')
        ->and($global->getCell('M7')->getValue())->toBe(60)
        ->and($global->getCell('P7')->getValue())->toBe(60)
        ->and($global->getCell('B9')->getValue())->toBe('EMPLOYEE SUBTOTAL')
        ->and($global->getCell('M9')->getValue())->toBe(150)
        ->and($global->getCell('P9')->getValue())->toBe(120)
        ->and($global->getCell('Q9')->getValue())->toBe(30)
        ->and($global->getCell('B10')->getValue())->toBe('GRAND TOTAL')
        ->and($global->getCell('M10')->getValue())->toBe(210)
        ->and($global->getCell('P10')->getValue())->toBe(180)
        ->and($global->getCell('Q10')->getValue())->toBe(30);

    $stubPath = (new PayrollStubExporter)->export($payPeriod, $employeeB);
    $stub = IOFactory::load($stubPath)->getActiveSheet();

    expect($stub->getCell('A10')->getValue())->toBe('EMPLOYEE SUBTOTAL')
        ->and($stub->getCell('O10')->getValue())->toBe(150)
        ->and($stub->getCell('R10')->getValue())->toBe(120)
        ->and($stub->getCell('S10')->getValue())->toBe(30)
        ->and($stub->getCell('A11')->getValue())->toBe('GRAND TOTAL')
        ->and($stub->getCell('O11')->getValue())->toBe(150)
        ->and($stub->getCell('R11')->getValue())->toBe(120)
        ->and($stub->getCell('S11')->getValue())->toBe(30);

    unlink($globalPath);
    unlink($stubPath);
});

test('payroll exports derive exact hours and totals from canonical minutes', function () {
    $company = Company::factory()->create();
    $payPeriod = PayPeriod::factory()->forCompany($company)->create([
        'start_date' => '2024-01-20',
        'end_date' => '2024-01-27',
        'status' => 'processed',
    ]);
    $employee = Employee::factory()->forCompany($company)->create();

    foreach (['2024-01-22', '2024-01-23'] as $date) {
        PayrollResult::factory()->forCompany($company)->forPayPeriod($payPeriod)->forEmployee($employee)->create([
            'date' => $date,
            'day_snapshot' => reportingSnapshot($employee->external_id, $employee->full_name, $date, 1, 1, 1),
        ]);
    }

    $payrollPath = (new PayrollExcelExporter)->export($payPeriod);
    $payrollSheet = IOFactory::load($payrollPath)->getActiveSheet();

    expect($payrollSheet->getCell('M8')->getValue())->toBe(2)
        ->and($payrollSheet->getCell('P8')->getValue())->toBe(2)
        ->and($payrollSheet->getCell('Q8')->getValue())->toBe(2)
        ->and($payrollSheet->getCell('E8')->getValue())->toBe('=M8/60')
        ->and($payrollSheet->getCell('F8')->getValue())->toBe('=P8/60')
        ->and($payrollSheet->getCell('G8')->getValue())->toBe('=Q8/60')
        ->and(round((float) $payrollSheet->getCell('E8')->getCalculatedValue() * 60, 8))->toBe(2.0)
        ->and($payrollSheet->getCell('M9')->getValue())->toBe(2)
        ->and($payrollSheet->getCell('E9')->getValue())->toBe('=M9/60');

    $stubPath = (new PayrollStubExporter)->export($payPeriod, $employee);
    $stubSheet = IOFactory::load($stubPath)->getActiveSheet();

    expect($stubSheet->getCell('O11')->getValue())->toBe(2)
        ->and($stubSheet->getCell('R11')->getValue())->toBe(2)
        ->and($stubSheet->getCell('S11')->getValue())->toBe(2)
        ->and($stubSheet->getCell('E11')->getValue())->toBe('=O11/60')
        ->and($stubSheet->getCell('F11')->getValue())->toBe('=R11/60')
        ->and($stubSheet->getCell('G11')->getValue())->toBe('=S11/60')
        ->and(round((float) $stubSheet->getCell('E11')->getCalculatedValue() * 60, 8))->toBe(2.0)
        ->and($stubSheet->getCell('O12')->getValue())->toBe(2)
        ->and($stubSheet->getCell('E12')->getValue())->toBe('=O12/60');
});
