<?php

use App\Models\Company;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\JustifiedAbsence;
use App\Models\PayPeriod;
use App\Models\WorkSchedule;
use App\Models\RawMark;
use App\Models\UploadedFile;
use App\Services\Payroll\PayrollCalculator;
use App\Services\PayrollRules;
use Carbon\Carbon;
use Illuminate\Support\Collection;

beforeEach(function () {
    $this->seed(\Database\Seeders\PermissionRoleSeeder::class);
    $this->calculator = new PayrollCalculator(new App\Services\Payroll\BandSplitter, new PayrollRules);
});

function marksForDay(Company $company, PayPeriod $payPeriod, Employee $employee, string $date, array $times): Collection
{
    $file = UploadedFile::factory()->forCompany($company)->forPayPeriod($payPeriod)->create();

    return collect($times)->map(function (string $time) use ($company, $payPeriod, $file, $employee) {
        return RawMark::factory()->forCompany($company)->forPayPeriod($payPeriod)->forUploadedFile($file)->forEmployee($employee)->create([
            'event_at' => Carbon::parse($time),
            'status' => 'valid',
        ]);
    });
}

test('weekday monday 05:51 to 15:41 matches employee 15496 example', function () {
    $company = Company::factory()->create();
    $payPeriod = PayPeriod::factory()->forCompany($company)->create();
    $employee = Employee::factory()->forCompany($company)->create();
    $date = Carbon::parse('2025-10-06'); // Monday

    $marks = marksForDay($company, $payPeriod, $employee, $date->toDateString(), [
        '2025-10-06 05:51:00',
        '2025-10-06 15:41:00',
    ]);

    $result = $this->calculator->calculateForDay($company, $employee, $date, $marks);

    expect($result->ordinaryHours)->toEqual(8.0)
        ->and($result->extra25Hours)->toBe(2)
        ->and($result->extra75Hours)->toBe(0)
        ->and($result->extra50Hours)->toBe(0)
        ->and($result->extra100Hours)->toBe(0)
        ->and($result->workedHours)->toBeBetween(9.82, 9.84);
});

test('weekday tuesday 05:14 to 17:27 uses half up rounding for extras', function () {
    $company = Company::factory()->create();
    $payPeriod = PayPeriod::factory()->forCompany($company)->create();
    $employee = Employee::factory()->forCompany($company)->create();
    $date = Carbon::parse('2025-10-07'); // Tuesday

    $marks = marksForDay($company, $payPeriod, $employee, $date->toDateString(), [
        '2025-10-07 05:14:00',
        '2025-10-07 17:27:00',
    ]);

    $result = $this->calculator->calculateForDay($company, $employee, $date, $marks);

    // The user report shows 4 extra 25% for this example, but 3.45h rounds to 3
    // under PHP_ROUND_HALF_UP. We follow the explicit half-up rule and document
    // the inconsistency as metadata.
    expect($result->ordinaryHours)->toEqual(8.0)
        ->and($result->extra25Hours)->toBe(3)
        ->and($result->extra75Hours)->toBe(1)
        ->and($result->extra50Hours)->toBe(0)
        ->and($result->extra100Hours)->toBe(0)
        ->and($result->workedHours)->toBeBetween(12.21, 12.22);

    expect($result->metadata['raw_split']['extra_25'])->toBeBetween(3.44, 3.46);
});

test('weekday uses custom overtime bands from work schedule', function () {
    $company = Company::factory()->create();
    $payPeriod = PayPeriod::factory()->forCompany($company)->create();
    $employee = Employee::factory()->forCompany($company)->create();
    $date = Carbon::parse('2025-10-07'); // Tuesday

    WorkSchedule::withoutCompanyScope()->updateOrCreate(
        [
            'company_id' => $company->id,
            'day_of_week' => $date->dayOfWeek,
        ],
        [
            'is_working_day' => true,
            'base_ordinary_hours' => 8.00,
            'banding_json' => [
                ['start' => '06:00', 'end' => '07:00', 'extra_percent' => 0],
                ['start' => '07:00', 'end' => '00:00', 'extra_percent' => 100],
            ],
            'notes' => null,
        ]
    );

    $marks = marksForDay($company, $payPeriod, $employee, $date->toDateString(), [
        '2025-10-07 06:00:00',
        '2025-10-07 14:00:00',
    ]);

    $result = $this->calculator->calculateForDay($company, $employee, $date, $marks);

    expect($result->ordinaryHours)->toEqual(1.0)
        ->and($result->extra100Hours)->toBe(7)
        ->and($result->extra25Hours)->toBe(0)
        ->and($result->extra50Hours)->toBe(0)
        ->and($result->extra75Hours)->toBe(0)
        ->and($result->metadata['raw_split']['extra_100'])->toEqual(7.0)
        ->and($result->metadata['raw_split']['extra_25'])->toEqual(0.0);
});

test('saturday 06:00 to 14:00 caps ordinary at four and shifts rest to extra 25', function () {
    $company = Company::factory()->create();
    $payPeriod = PayPeriod::factory()->forCompany($company)->create();
    $employee = Employee::factory()->forCompany($company)->create();
    $date = Carbon::parse('2025-10-04'); // Saturday

    $marks = marksForDay($company, $payPeriod, $employee, $date->toDateString(), [
        '2025-10-04 06:00:00',
        '2025-10-04 14:00:00',
    ]);

    $result = $this->calculator->calculateForDay($company, $employee, $date, $marks);

    expect($result->ordinaryHours)->toEqual(4.0)
        ->and($result->extra25Hours)->toBe(4)
        ->and($result->extra50Hours)->toBe(0)
        ->and($result->extra75Hours)->toBe(0)
        ->and($result->extra100Hours)->toBe(0);
});

test('saturday 06:00 to 10:00 keeps all four hours ordinary', function () {
    $company = Company::factory()->create();
    $payPeriod = PayPeriod::factory()->forCompany($company)->create();
    $employee = Employee::factory()->forCompany($company)->create();
    $date = Carbon::parse('2025-10-04');

    $marks = marksForDay($company, $payPeriod, $employee, $date->toDateString(), [
        '2025-10-04 06:00:00',
        '2025-10-04 10:00:00',
    ]);

    $result = $this->calculator->calculateForDay($company, $employee, $date, $marks);

    expect($result->ordinaryHours)->toEqual(4.0)
        ->and($result->extra25Hours)->toBe(0)
        ->and($result->extra50Hours)->toBe(0)
        ->and($result->extra75Hours)->toBe(0)
        ->and($result->extra100Hours)->toBe(0);
});

test('saturday 06:00 to 12:00 caps ordinary and converts clock ordinary excess to extra 25', function () {
    $company = Company::factory()->create();
    $payPeriod = PayPeriod::factory()->forCompany($company)->create();
    $employee = Employee::factory()->forCompany($company)->create();
    $date = Carbon::parse('2025-10-04');

    $marks = marksForDay($company, $payPeriod, $employee, $date->toDateString(), [
        '2025-10-04 06:00:00',
        '2025-10-04 12:00:00',
    ]);

    $result = $this->calculator->calculateForDay($company, $employee, $date, $marks);

    expect($result->ordinaryHours)->toEqual(4.0)
        ->and($result->extra25Hours)->toBe(2)
        ->and($result->extra50Hours)->toBe(0)
        ->and($result->extra75Hours)->toBe(0)
        ->and($result->extra100Hours)->toBe(0);
});

test('saturday 04:00 to 12:00 caps ordinary and shifts pre six excess to extra 25', function () {
    $company = Company::factory()->create();
    $payPeriod = PayPeriod::factory()->forCompany($company)->create();
    $employee = Employee::factory()->forCompany($company)->create();
    $date = Carbon::parse('2025-10-04');

    $marks = marksForDay($company, $payPeriod, $employee, $date->toDateString(), [
        '2025-10-04 04:00:00',
        '2025-10-04 12:00:00',
    ]);

    $result = $this->calculator->calculateForDay($company, $employee, $date, $marks);

    expect($result->ordinaryHours)->toEqual(4.0)
        ->and($result->extra75Hours)->toBe(2)
        ->and($result->extra25Hours)->toBe(2)
        ->and($result->extra50Hours)->toBe(0)
        ->and($result->extra100Hours)->toBe(0);
});

test('sunday 06:00 to 14:00 is all extra 100', function () {
    $company = Company::factory()->create();
    $payPeriod = PayPeriod::factory()->forCompany($company)->create();
    $employee = Employee::factory()->forCompany($company)->create();
    $date = Carbon::parse('2025-10-05'); // Sunday

    $marks = marksForDay($company, $payPeriod, $employee, $date->toDateString(), [
        '2025-10-05 06:00:00',
        '2025-10-05 14:00:00',
    ]);

    $result = $this->calculator->calculateForDay($company, $employee, $date, $marks);

    expect($result->ordinaryHours)->toEqual(0.0)
        ->and($result->extra100Hours)->toBe(8)
        ->and($result->extra25Hours)->toBe(0)
        ->and($result->extra50Hours)->toBe(0)
        ->and($result->extra75Hours)->toBe(0);
});

test('holiday 06:00 to 14:00 is all extra 100', function () {
    $company = Company::factory()->create();
    $payPeriod = PayPeriod::factory()->forCompany($company)->create();
    $employee = Employee::factory()->forCompany($company)->create();
    $date = Carbon::parse('2025-10-07');

    Holiday::factory()->forCompany($company)->create([
        'date' => $date->toDateString(),
        'is_active' => true,
    ]);

    $marks = marksForDay($company, $payPeriod, $employee, $date->toDateString(), [
        '2025-10-07 06:00:00',
        '2025-10-07 14:00:00',
    ]);

    $result = $this->calculator->calculateForDay($company, $employee, $date, $marks);

    expect($result->ordinaryHours)->toEqual(0.0)
        ->and($result->extra100Hours)->toBe(8)
        ->and($result->extra25Hours)->toBe(0)
        ->and($result->extra50Hours)->toBe(0)
        ->and($result->extra75Hours)->toBe(0);
});

test('justified absence on tuesday pays eight ordinary hours', function () {
    $company = Company::factory()->create();
    $payPeriod = PayPeriod::factory()->forCompany($company)->create();
    $employee = Employee::factory()->forCompany($company)->create();
    $date = Carbon::parse('2025-10-07'); // Tuesday

    $absence = JustifiedAbsence::factory()->forCompany($company)->forPayPeriod($payPeriod)->forEmployee($employee)->create([
        'date' => $date->toDateString(),
    ]);

    $result = $this->calculator->calculateForDay($company, $employee, $date, collect(), $absence);

    expect($result->ordinaryHours)->toEqual(8.0)
        ->and($result->workedHours)->toEqual(8.0)
        ->and($result->extra25Hours)->toBe(0)
        ->and($result->isJustified)->toBeTrue()
        ->and($result->isAbsence)->toBeTrue()
        ->and($result->unjustified)->toBeFalse();
});

test('justified absence on saturday pays four ordinary hours', function () {
    $company = Company::factory()->create();
    $payPeriod = PayPeriod::factory()->forCompany($company)->create();
    $employee = Employee::factory()->forCompany($company)->create();
    $date = Carbon::parse('2025-10-04'); // Saturday

    $absence = JustifiedAbsence::factory()->forCompany($company)->forPayPeriod($payPeriod)->forEmployee($employee)->create([
        'date' => $date->toDateString(),
    ]);

    $result = $this->calculator->calculateForDay($company, $employee, $date, collect(), $absence);

    expect($result->ordinaryHours)->toEqual(4.0)
        ->and($result->workedHours)->toEqual(4.0)
        ->and($result->extra25Hours)->toBe(0)
        ->and($result->isJustified)->toBeTrue()
        ->and($result->isAbsence)->toBeTrue();
});

test('unjustified missing tuesday flags absence and unjustified', function () {
    $company = Company::factory()->create();
    $employee = Employee::factory()->forCompany($company)->create();
    $date = Carbon::parse('2025-10-07');

    $result = $this->calculator->calculateForDay($company, $employee, $date, collect());

    expect($result->ordinaryHours)->toEqual(0.0)
        ->and($result->workedHours)->toEqual(0.0)
        ->and($result->isAbsence)->toBeTrue()
        ->and($result->unjustified)->toBeTrue()
        ->and($result->isJustified)->toBeFalse();
});

test('single mark for day is flagged as missing paired mark', function () {
    $company = Company::factory()->create();
    $payPeriod = PayPeriod::factory()->forCompany($company)->create();
    $employee = Employee::factory()->forCompany($company)->create();
    $date = Carbon::parse('2025-10-07');

    $marks = marksForDay($company, $payPeriod, $employee, $date->toDateString(), [
        '2025-10-07 08:00:00',
    ]);

    $result = $this->calculator->calculateForDay($company, $employee, $date, $marks);

    expect($result->isAbsence)->toBeTrue()
        ->and($result->unjustified)->toBeFalse()
        ->and($result->isJustified)->toBeFalse()
        ->and($result->workedHours)->toEqual(0.0);
});

test('non working day without marks returns skip marker', function () {
    $company = Company::factory()->create();
    $employee = Employee::factory()->forCompany($company)->create();
    $date = Carbon::parse('2025-10-05'); // Sunday

    $result = $this->calculator->calculateForDay($company, $employee, $date, collect());

    expect($result->metadata['skip'])->toBeTrue()
        ->and($result->isAbsence)->toBeFalse()
        ->and($result->unjustified)->toBeFalse();
});
