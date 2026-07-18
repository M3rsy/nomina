<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('pay_period_id')->constrained('pay_periods')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->date('date');
            $table->dateTime('entry_at')->nullable();
            $table->dateTime('exit_at')->nullable();
            $table->decimal('worked_hours', 6, 2)->default(0);
            $table->decimal('ordinary_hours', 6, 2)->default(0);
            $table->integer('extra_25_hours')->default(0);
            $table->integer('extra_50_hours')->default(0);
            $table->integer('extra_75_hours')->default(0);
            $table->integer('extra_100_hours')->default(0);
            $table->boolean('is_absence')->default(false);
            $table->boolean('is_justified')->default(false);
            $table->boolean('unjustified')->default(false);
            $table->text('notes')->nullable();
            $table->string('rules_version', 20)->default('1');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'pay_period_id', 'employee_id', 'date']);
            $table->index(['pay_period_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_results');
    }
};
