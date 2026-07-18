<?php

use App\Models\Company;
use App\Models\Employee;
use App\Models\PayPeriod;
use App\Models\RawMark;
use App\Models\UploadedFile;
use App\Services\FileValidator;
use App\Services\Parsers\GlgParser;
use App\Services\Parsers\RawMarkPayload;
use Carbon\Carbon;
use Illuminate\Support\Collection;

function buildPayload(string $employeeId, string $eventAt, int $rowNumber): RawMarkPayload
{
    return new RawMarkPayload(
        employee_external_id: $employeeId,
        event_at: Carbon::parse($eventAt),
        raw_line: "{$employeeId}\t{$eventAt}",
        row_number: $rowNumber,
        source: 'glg',
    );
}

test('validator marks duplicate records by employee and event time', function () {
    $company = Company::factory()->create();
    $payPeriod = PayPeriod::factory()->forCompany($company)->create([
        'start_date' => '2026-01-01',
        'end_date' => '2026-01-31',
    ]);
    Employee::factory()->forCompany($company)->create(['external_id' => '13767']);

    $uploadedFile = UploadedFile::factory()->forCompany($company)->forPayPeriod($payPeriod)->create([
        'status' => 'pending',
    ]);

    $records = collect([
        buildPayload('13767', '2026-01-19 14:53:50', 1),
        buildPayload('13767', '2026-01-19 14:53:50', 2),
    ]);

    $validator = new FileValidator;
    $report = $validator->validate($uploadedFile, $records);

    expect($report->counts['duplicate'])->toBe(1);
    expect($report->counts['valid'])->toBe(1);
    expect(RawMark::where('uploaded_file_id', $uploadedFile->id)->where('status', 'duplicate')->exists())->toBeTrue();
    expect(RawMark::where('uploaded_file_id', $uploadedFile->id)->where('status', 'valid')->exists())->toBeTrue();
});

test('validator marks out of period records', function () {
    $company = Company::factory()->create();
    $payPeriod = PayPeriod::factory()->forCompany($company)->create([
        'start_date' => '2026-01-01',
        'end_date' => '2026-01-31',
    ]);
    Employee::factory()->forCompany($company)->create(['external_id' => '13767']);

    $uploadedFile = UploadedFile::factory()->forCompany($company)->forPayPeriod($payPeriod)->create([
        'status' => 'pending',
    ]);

    $records = collect([
        buildPayload('13767', '2026-02-05 08:00:00', 1),
    ]);

    $validator = new FileValidator;
    $report = $validator->validate($uploadedFile, $records);

    expect($report->counts['out_of_period'])->toBe(1);
    expect($report->counts['valid'])->toBe(0);
    expect(RawMark::where('status', 'out_of_period')->exists())->toBeTrue();
});

test('validator marks unknown employee records', function () {
    $company = Company::factory()->create();
    $payPeriod = PayPeriod::factory()->forCompany($company)->create([
        'start_date' => '2026-01-01',
        'end_date' => '2026-01-31',
    ]);
    Employee::factory()->forCompany($company)->create(['external_id' => '13767']);

    $uploadedFile = UploadedFile::factory()->forCompany($company)->forPayPeriod($payPeriod)->create([
        'status' => 'pending',
    ]);

    $records = collect([
        buildPayload('99999', '2026-01-19 14:53:50', 1),
    ]);

    $validator = new FileValidator;
    $report = $validator->validate($uploadedFile, $records);

    expect($report->counts['unknown_employee'])->toBe(1);
    expect($report->counts['valid'])->toBe(0);
    expect(RawMark::where('status', 'unknown_employee')->exists())->toBeTrue();
});

test('validator sets file status valid when all records are valid', function () {
    $company = Company::factory()->create();
    $payPeriod = PayPeriod::factory()->forCompany($company)->create([
        'start_date' => '2026-01-01',
        'end_date' => '2026-01-31',
    ]);
    Employee::factory()->forCompany($company)->create(['external_id' => '13767']);

    $uploadedFile = UploadedFile::factory()->forCompany($company)->forPayPeriod($payPeriod)->create([
        'status' => 'pending',
    ]);

    $records = collect([
        buildPayload('13767', '2026-01-19 14:53:50', 1),
    ]);

    $validator = new FileValidator;
    $validator->validate($uploadedFile, $records);

    $uploadedFile->refresh();
    expect($uploadedFile->status)->toBe('valid');
    expect($uploadedFile->validation_summary['total'])->toBe(1);
    expect($uploadedFile->validation_summary['valid'])->toBe(1);
});

test('validator sets file status valid_with_warnings when issues exist but some valid', function () {
    $company = Company::factory()->create();
    $payPeriod = PayPeriod::factory()->forCompany($company)->create([
        'start_date' => '2026-01-01',
        'end_date' => '2026-01-31',
    ]);
    Employee::factory()->forCompany($company)->create(['external_id' => '13767']);
    Employee::factory()->forCompany($company)->create(['external_id' => '44']);

    $uploadedFile = UploadedFile::factory()->forCompany($company)->forPayPeriod($payPeriod)->create([
        'status' => 'pending',
    ]);

    $records = collect([
        buildPayload('13767', '2026-01-19 14:53:50', 1),
        buildPayload('99999', '2026-01-19 14:53:50', 2),
    ]);

    $validator = new FileValidator;
    $validator->validate($uploadedFile, $records);

    $uploadedFile->refresh();
    expect($uploadedFile->status)->toBe('valid_with_warnings');
});

test('validator sets file status invalid when no valid records', function () {
    $company = Company::factory()->create();
    $payPeriod = PayPeriod::factory()->forCompany($company)->create([
        'start_date' => '2026-01-01',
        'end_date' => '2026-01-31',
    ]);

    $uploadedFile = UploadedFile::factory()->forCompany($company)->forPayPeriod($payPeriod)->create([
        'status' => 'pending',
    ]);

    $records = collect([
        buildPayload('99999', '2026-01-19 14:53:50', 1),
    ]);

    $validator = new FileValidator;
    $validator->validate($uploadedFile, $records);

    $uploadedFile->refresh();
    expect($uploadedFile->status)->toBe('invalid');
});
