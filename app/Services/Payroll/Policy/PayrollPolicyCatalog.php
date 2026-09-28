<?php

namespace App\Services\Payroll\Policy;

interface PayrollPolicyCatalog
{
    public function resolve(string $key): PayrollPolicyDefinition;
}
