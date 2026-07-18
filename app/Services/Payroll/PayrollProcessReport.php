<?php

namespace App\Services\Payroll;

class PayrollProcessReport
{
    public int $employeesProcessed = 0;

    public int $daysProcessed = 0;

    public int $resultsInserted = 0;

    public int $resultsUpdated = 0;

    public int $missingSingleMarkCount = 0;

    public int $justifiedAbsenceCount = 0;

    public int $unjustifiedAbsenceCount = 0;
}
