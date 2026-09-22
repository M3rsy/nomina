<?php

namespace App\Services\Payroll;

final readonly class CreateEmployeeFromUnknownMarkCommand
{
    public function __construct(
        public int $rawMarkId,
        public int $scheduleProfileId,
        public int $actorId,
        public string $paymentCode,
        public string $firstName,
        public string $lastName,
        public string $dni,
        public string $jobTitle,
        public string $hiredAt,
        public string $reason,
        public bool $assignAll = true,
        public ?string $sex = null,
        public ?string $birthDate = null,
        public ?string $address = null,
        public ?string $phone = null,
        public ?string $expectedSalary = null,
        public ?string $notes = null,
    ) {}
}
