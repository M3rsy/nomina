<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 64);
            $table->timestamp('occurred_at')->index();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('user_identifier', 150)->nullable();
            $table->text('description');
            $table->json('metadata')->nullable();
            $table->string('subject_type', 120)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('source_type', 120);
            $table->unsignedBigInteger('source_id');
            $table->string('source_revision', 120)->default('created');
            $table->timestamps();

            $table->index(['company_id', 'occurred_at'], 'audit_entries_company_occurred_idx');
            $table->index(['company_id', 'type', 'occurred_at'], 'audit_entries_company_type_occurred_idx');
            $table->index(['company_id', 'actor_id', 'occurred_at'], 'audit_entries_company_actor_occurred_idx');
            $table->index(['company_id', 'user_identifier', 'occurred_at'], 'audit_entries_company_user_occurred_idx');
            $table->index(['subject_type', 'subject_id'], 'audit_entries_subject_idx');
            $table->unique(['source_type', 'source_id', 'source_revision'], 'audit_entries_source_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_entries');
    }
};
