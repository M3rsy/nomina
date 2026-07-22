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
        ->and($data[4])->toBe(['Codigo', 'NOMBRE', 'Entrada', 'Salida', 'Cantidad Horas', 'Horas Ordinarias', 'Horas Ext 25%', 'Horas Ext 50%', 'Horas Ext 75%', 'Horas Ext 100%'])
        ->and($data[5])->toContain(1, 'Juan Perez')
        ->and($data[5][2])->toContain('2024-01-22')
        ->and($data[5][4])->toBe(9.0)
        ->and($data[5][5])->toBe(8.0)
        ->and($data[5][6])->toBe(0.5)
        ->and($data[5][7])->toBe(0.0)
        ->and($data[5][8])->toBe(0.0)
        ->and($data[5][9])->toBe(0.0);
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
            'worked_minutes' => 1,
            'ordinary_minutes' => 1,
            'extra_25_minutes' => 1,
            'worked_hours' => 0.02,
            'ordinary_hours' => 0.02,
            'extra_25_hours' => 0.02,
        ]);
    }

    $payrollPath = (new PayrollExcelExporter)->export($payPeriod);
    $payrollSheet = IOFactory::load($payrollPath)->getActiveSheet();

    expect(round((float) $payrollSheet->getCell('E6')->getValue() * 60, 8))->toBe(1.0)
        ->and(round((float) $payrollSheet->getCell('F6')->getValue() * 60, 8))->toBe(1.0)
        ->and(round((float) $payrollSheet->getCell('G6')->getValue() * 60, 8))->toBe(1.0);

    $stubPath = (new PayrollStubExporter)->export($payPeriod, $employee);
    $stubSheet = IOFactory::load($stubPath)->getActiveSheet();

    expect(round((float) $stubSheet->getCell('E9')->getValue() * 60, 8))->toBe(1.0)
        ->and(round((float) $stubSheet->getCell('F9')->getValue() * 60, 8))->toBe(1.0)
        ->and(round((float) $stubSheet->getCell('G9')->getValue() * 60, 8))->toBe(1.0)
        ->and(round((float) $stubSheet->getCell('E11')->getValue() * 60, 8))->toBe(2.0)
        ->and(round((float) $stubSheet->getCell('F11')->getValue() * 60, 8))->toBe(2.0)
        ->and(round((float) $stubSheet->getCell('G11')->getValue() * 60, 8))->toBe(2.0);
});
