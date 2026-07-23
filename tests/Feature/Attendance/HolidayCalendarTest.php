<?php

use App\Models\Company;
use App\Models\Holiday;
use App\Models\PayPeriod;
use App\Services\Attendance\HolidayCalendar;
use Illuminate\Validation\ValidationException;

test('captured holiday context is immutable and only real mutations advance its generation', function () {
    $company = Company::factory()->create();
    $calendar = app(HolidayCalendar::class);
    $before = $calendar->capture($company, '2026-01-05', '2026-01-05');

    $holiday = $calendar->save($company, null, [
        'date' => '2026-01-05',
        'name' => 'Founders Day',
        'description' => null,
        'is_active' => true,
    ]);
    $after = $calendar->capture($company, '2026-01-05', '2026-01-05');

    $calendar->save($company, $holiday, [
        'date' => '2026-01-05',
        'name' => 'Founders Day',
        'description' => null,
        'is_active' => true,
    ]);
    $afterNoOp = $calendar->capture($company, '2026-01-05', '2026-01-05');

    expect($before->generation('2026-01-05'))->toBe(0)
        ->and($before->isHoliday('2026-01-05'))->toBeFalse()
        ->and($after->generation('2026-01-05'))->toBe(1)
        ->and($after->isHoliday('2026-01-05'))->toBeTrue()
        ->and($before->generation('2026-01-05'))->toBe(0)
        ->and($before->isHoliday('2026-01-05'))->toBeFalse()
        ->and($afterNoOp->generation('2026-01-05'))->toBe(1);
});

test('attendance-locked periods reject every affected holiday mutation atomically', function (string $operation) {
    $company = Company::factory()->create();
    $calendar = app(HolidayCalendar::class);
    $oldDate = '2026-02-02';
    $newDate = '2026-02-03';
    $holiday = $operation === 'create'
        ? null
        : Holiday::factory()->forCompany($company)->inactive()->create(['date' => $oldDate]);
    $lockedDate = $operation === 'move-new' ? $newDate : $oldDate;

    PayPeriod::factory()->forCompany($company)->create([
        'start_date' => '2026-02-01',
        'end_date' => '2026-02-04',
        'status' => 'draft',
    ]);
    PayPeriod::factory()->forCompany($company)->create([
        'start_date' => $lockedDate,
        'end_date' => $lockedDate,
        'status' => 'processed',
    ]);

    $mutation = match ($operation) {
        'create' => fn () => $calendar->save($company, null, [
            'date' => $oldDate,
            'name' => 'Founders Day',
            'description' => null,
            'is_active' => true,
        ]),
        'update' => fn () => $calendar->save($company, $holiday, [
            'date' => $oldDate,
            'name' => 'Founders Day',
            'description' => null,
            'is_active' => true,
        ]),
        'move-old', 'move-new' => fn () => $calendar->save($company, $holiday, [
            'date' => $newDate,
            'name' => $holiday->name,
            'description' => $holiday->description,
            'is_active' => $holiday->is_active,
        ]),
        'delete' => fn () => $calendar->delete($holiday),
    };

    expect($mutation)->toThrow(ValidationException::class);

    $after = $calendar->capture($company, $oldDate, $newDate);
    $persisted = $holiday?->fresh();

    expect($after->generation($oldDate))->toBe(0)
        ->and($after->generation($newDate))->toBe(0)
        ->and(Holiday::withoutCompanyScope()->where('company_id', $company->id)->count())
        ->toBe($operation === 'create' ? 0 : 1)
        ->and($persisted?->date->toDateString())->toBe($operation === 'create' ? null : $oldDate)
        ->and($persisted?->is_active)->toBe($operation === 'create' ? null : false);
})->with(['create', 'update', 'move-old', 'move-new', 'delete']);
