<?php

namespace App\Services\Payroll\Policy;

use DomainException;

final class UnsupportedPayrollPolicy extends DomainException
{
    public function __construct(public readonly string $key)
    {
        parent::__construct("Unsupported payroll policy [{$key}].");
    }
}
