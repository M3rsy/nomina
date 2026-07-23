<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->index()->constrained()->onDelete('set null')->after('email_verified_at');
            $table->boolean('is_active')->default(true)->after('company_id');
            $table->timestamp('password_changed_at')->nullable()->after('is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['company_id']);
            $table->dropForeign(['company_id']);
            $table->dropColumn(['company_id', 'is_active', 'password_changed_at']);
        });
    }
};
