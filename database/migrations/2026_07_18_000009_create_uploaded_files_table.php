<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('uploaded_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('pay_period_id')->constrained('pay_periods')->cascadeOnDelete();
            $table->string('original_name');
            $table->string('stored_name');
            $table->string('disk')->default('local');
            $table->string('path');
            $table->string('mime')->nullable();
            $table->string('extension', 10);
            $table->unsignedBigInteger('size_bytes');
            $table->string('encoding', 20)->nullable();
            $table->string('sha256', 64);
            $table->string('status')->default('pending');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('validation_summary')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'sha256']);
            $table->index('sha256');
            $table->index('company_id');
            $table->index('pay_period_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('uploaded_files');
    }
};
