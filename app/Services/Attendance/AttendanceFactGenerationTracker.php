<?php

namespace App\Services\Attendance;

use App\Models\AttendanceFactGeneration;
use App\Models\Employee;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class AttendanceFactGenerationTracker
{
    public function current(Employee $employee, CarbonInterface|string $workDate): int
    {
        return (int) (AttendanceFactGeneration::withoutCompanyScope()
            ->where('company_id', $employee->company_id)
            ->where('employee_id', $employee->id)
            ->whereDate('work_date', CarbonImmutable::parse($workDate)->toDateString())
            ->value('generation') ?? 0);
    }

    public function advance(Employee $employee, CarbonInterface|string $workDate): int
    {
        $date = CarbonImmutable::parse($workDate)->toDateString();

        return DB::transaction(function () use ($employee, $date): int {
            DB::table('attendance_fact_generations')->insertOrIgnore([
                'company_id' => $employee->company_id,
                'employee_id' => $employee->id,
                'work_date' => $date,
                'generation' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $generation = AttendanceFactGeneration::withoutCompanyScope()
                ->where('company_id', $employee->company_id)
                ->where('employee_id', $employee->id)
                ->whereDate('work_date', $date)
                ->lockForUpdate()
                ->firstOrFail();

            $generation->increment('generation');

            return $generation->refresh()->generation;
        });
    }
}
