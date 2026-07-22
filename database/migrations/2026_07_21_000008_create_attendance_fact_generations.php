<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_fact_generations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('work_date');
            $table->unsignedBigInteger('generation')->default(0);
            $table->timestamps();

            $table->unique(
                ['company_id', 'employee_id', 'work_date'],
                'attendance_fact_generation_context_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_fact_generations');
    }
};
