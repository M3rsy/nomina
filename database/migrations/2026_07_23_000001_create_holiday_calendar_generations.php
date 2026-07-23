<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('holiday_calendar_generations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->date('calendar_date');
            $table->unsignedBigInteger('generation')->default(0);
            $table->timestamps();

            $table->unique(['company_id', 'calendar_date'], 'holiday_calendar_generation_context_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('holiday_calendar_generations');
    }
};
