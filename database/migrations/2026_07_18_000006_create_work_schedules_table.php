<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_schedules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('day_of_week');
            $table->boolean('is_working_day')->default(true);
            $table->decimal('base_ordinary_hours', 4, 2)->default(8.00);
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'day_of_week']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_schedules');
    }
};
