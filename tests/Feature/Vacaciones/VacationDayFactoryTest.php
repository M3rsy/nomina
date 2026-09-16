<?php

use App\Models\Company;
use App\Models\Employee;
use App\Models\Vacation;
use App\Models\VacationDay;
use App\Services\CurrentCompany;
use Illuminate\Database\Eloquent\ModelNotFoundException;

test('factory resolves an explicit vacation outside the active company context', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $employeeB = Employee::factory()->forCompany($companyB)->create();
    $vacationB = Vacation::factory()->for($companyB)->for($employeeB)->create();

    app(CurrentCompany::class)->set($companyA);

    $day = VacationDay::factory()->for($vacationB)->create();

    expect($day->vacation_id)->toBe($vacationB->id)
        ->and($day->company_id)->toBe($companyB->id)
        ->and($day->employee_id)->toBe($employeeB->id);
});

test('factory fails clearly when an explicit vacation does not exist', function () {
    expect(fn () => VacationDay::factory()->create(['vacation_id' => PHP_INT_MAX]))
        ->toThrow(ModelNotFoundException::class);
});
