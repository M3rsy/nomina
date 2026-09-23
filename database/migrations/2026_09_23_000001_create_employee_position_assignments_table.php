<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_position_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('title', 100);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason');
            $table->timestamps();

            $table->unique(['employee_id', 'effective_from']);
            $table->index(
                ['company_id', 'employee_id', 'effective_from', 'effective_to'],
                'employee_position_effective_idx',
            );
        });

        $now = now();

        DB::table('employees')
            ->whereNotNull('job_title')
            ->orderBy('id')
            ->each(function (object $employee) use ($now): void {
                $title = trim((string) $employee->job_title);

                if ($title === '') {
                    return;
                }

                $effectiveFrom = $employee->hired_at
                    ? CarbonImmutable::parse($employee->hired_at)->toDateString()
                    : ($employee->created_at
                        ? CarbonImmutable::parse($employee->created_at)->toDateString()
                        : '1970-01-01');

                DB::table('employee_position_assignments')->insert([
                    'company_id' => $employee->company_id,
                    'employee_id' => $employee->id,
                    'title' => $title,
                    'effective_from' => $effectiveFrom,
                    'effective_to' => null,
                    'assigned_by' => null,
                    'reason' => 'Asignación inicial migrada',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_position_assignments');
    }
};
