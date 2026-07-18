<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->onDelete('cascade')->index();
            $table->string('external_id', 50);
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('dni', 32);
            $table->char('sex', 1)->nullable();
            $table->date('birth_date')->nullable();
            $table->string('address', 255)->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('job_title', 100)->nullable();
            $table->decimal('expected_salary', 10, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->date('hired_at')->nullable();
            $table->text('notes')->nullable();

            if (DB::getDriverName() === 'pgsql') {
                $table->jsonb('metadata')->nullable();
            } else {
                $table->text('metadata')->nullable();
            }

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'external_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
