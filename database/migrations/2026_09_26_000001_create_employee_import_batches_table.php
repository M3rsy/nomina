<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_import_batches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('original_filename', 255);
            $table->string('status', 16);
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('read_rows')->default(0);
            $table->unsignedInteger('imported_rows')->default(0);
            $table->json('error_summary')->nullable();
            $table->json('error_details')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status', 'created_at'], 'employee_import_batches_company_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_import_batches');
    }
};
