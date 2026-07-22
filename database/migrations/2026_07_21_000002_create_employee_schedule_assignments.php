<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_schedule_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('work_schedule_profile_id')->constrained()->restrictOnDelete();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason');
            $table->timestamps();

            $table->unique(['employee_id', 'effective_from']);
            $table->index(['company_id', 'employee_id', 'effective_from', 'effective_to'], 'employee_schedule_effective_idx');
        });

        $profiles = DB::table('work_schedule_profiles')
            ->where('profile_key', 'general')
            ->where('is_active', true)
            ->orderByDesc('version')
            ->get()
            ->unique('company_id')
            ->pluck('id', 'company_id');
        $now = now();

        DB::table('employees')->orderBy('id')->each(function (object $employee) use ($profiles, $now): void {
            $profileId = $profiles->get($employee->company_id);

            if ($profileId === null) {
                return;
            }

            DB::table('employee_schedule_assignments')->insert([
                'company_id' => $employee->company_id,
                'employee_id' => $employee->id,
                'work_schedule_profile_id' => $profileId,
                'effective_from' => $employee->hired_at ?: '1970-01-01',
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
        Schema::dropIfExists('employee_schedule_assignments');
    }
};
