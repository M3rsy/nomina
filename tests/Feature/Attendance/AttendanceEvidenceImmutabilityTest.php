<?php

use App\Models\Company;
use App\Models\Employee;
use App\Models\PayPeriod;
use App\Models\RawMark;
use App\Models\UploadedFile;

test('imported raw mark evidence cannot change after creation', function () {
    $company = Company::factory()->create();
    $period = PayPeriod::factory()->forCompany($company)->create();
    $file = UploadedFile::factory()->forCompany($company)->forPayPeriod($period)->create();
    $mark = RawMark::factory()->forCompany($company)->forPayPeriod($period)
        ->forUploadedFile($file)->create([
            'employee_external_id' => '44',
            'raw_line' => 'original clock line',
            'source' => 'glg',
            'row_number' => 1,
        ]);
    $otherCompany = Company::factory()->create();
    $otherPeriod = PayPeriod::factory()->forCompany($otherCompany)->create();
    $otherFile = UploadedFile::factory()->forCompany($otherCompany)->forPayPeriod($otherPeriod)->create();

    $changes = [
        'company_id' => $otherCompany->id,
        'pay_period_id' => $otherPeriod->id,
        'uploaded_file_id' => $otherFile->id,
        'employee_external_id' => '13767',
        'raw_line' => 'tampered clock line',
        'source' => 'attlog',
        'row_number' => 2,
    ];

    foreach ($changes as $field => $value) {
        $mark->refresh();
        $original = $mark->getAttribute($field);

        expect(fn () => $mark->update([$field => $value]))
            ->toThrow(LogicException::class);

        expect($mark->fresh()->getAttribute($field))->toEqual($original);
    }
});

test('attendance marks cannot be physically deleted', function () {
    $mark = RawMark::factory()->create();

    expect(fn () => $mark->delete())
        ->toThrow(LogicException::class, 'Attendance records must be deleted logically.');

    expect(RawMark::withoutCompanyScope()->whereKey($mark)->exists())->toBeTrue();
});

test('uploaded source identity cannot change after creation', function () {
    $file = UploadedFile::factory()->create([
        'path' => 'uploads/original.txt',
        'sha256' => str_repeat('a', 64),
    ]);

    foreach (['path' => 'uploads/replacement.txt', 'sha256' => str_repeat('b', 64)] as $field => $value) {
        $file->refresh();
        $original = $file->getAttribute($field);

        expect(fn () => $file->update([$field => $value]))
            ->toThrow(LogicException::class);

        expect($file->fresh()->getAttribute($field))->toBe($original);
    }
});

test('normalized corrections and validation results remain mutable', function () {
    $company = Company::factory()->create();
    $period = PayPeriod::factory()->forCompany($company)->create();
    $file = UploadedFile::factory()->forCompany($company)->forPayPeriod($period)->create();
    $employee = Employee::factory()->forCompany($company)->create();
    $mark = RawMark::factory()->forCompany($company)->forPayPeriod($period)
        ->forUploadedFile($file)->create();

    $mark->update([
        'employee_id' => $employee->id,
        'event_at' => '2026-07-21 06:15:00',
        'status' => 'corrected',
        'notes' => 'Audited correction',
        'metadata' => ['revisions' => [['action' => 'edit_event_at']]],
    ]);
    $file->update([
        'status' => 'valid',
        'validation_summary' => ['total' => 1, 'valid' => 1],
    ]);

    expect($mark->fresh()->employee_id)->toBe($employee->id)
        ->and($mark->fresh()->event_at->toDateTimeString())->toBe('2026-07-21 06:15:00')
        ->and($mark->fresh()->status)->toBe('corrected')
        ->and($file->fresh()->status)->toBe('valid')
        ->and($file->fresh()->validation_summary['valid'])->toBe(1);
});
