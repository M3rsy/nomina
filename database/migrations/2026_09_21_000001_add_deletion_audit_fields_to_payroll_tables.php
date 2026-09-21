<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['pay_periods', 'uploaded_files'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->text('deletion_reason')->nullable();
                $table->foreignId('deleted_by')->nullable()->after('deleted_at')->constrained('users')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (['pay_periods', 'uploaded_files'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropForeign(['deleted_by']);
                $table->dropColumn(['deletion_reason', 'deleted_by']);
            });
        }
    }
};
