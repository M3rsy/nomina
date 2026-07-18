<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('raw_marks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('pay_period_id')->constrained('pay_periods')->cascadeOnDelete();
            $table->foreignId('uploaded_file_id')->constrained('uploaded_files')->cascadeOnDelete();
            $table->string('employee_external_id', 50);
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->dateTime('event_at');
            $table->text('raw_line');
            $table->string('source', 20);
            $table->integer('row_number');
            $table->string('status')->default('pending');
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['uploaded_file_id', 'row_number']);
            $table->index(['company_id', 'pay_period_id']);
            $table->index('uploaded_file_id');
            $table->index('employee_external_id');
            $table->index('event_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('raw_marks');
    }
};
