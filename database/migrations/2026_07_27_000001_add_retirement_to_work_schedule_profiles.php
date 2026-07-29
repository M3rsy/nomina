<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_schedule_profiles', function (Blueprint $table): void {
            $table->timestamp('retired_at')->nullable()->after('change_reason');
            $table->foreignId('retired_by')->nullable()->after('retired_at')->constrained('users')->nullOnDelete();
            $table->string('retirement_reason', 500)->nullable()->after('retired_by');
            $table->foreignId('replacement_profile_id')
                ->nullable()
                ->after('retirement_reason')
                ->constrained('work_schedule_profiles')
                ->restrictOnDelete();
            $table->index(['company_id', 'is_active', 'retired_at'], 'work_schedule_profiles_availability_idx');
        });
    }

    public function down(): void
    {
        Schema::table('work_schedule_profiles', function (Blueprint $table): void {
            $table->dropIndex('work_schedule_profiles_availability_idx');
            $table->dropConstrainedForeignId('replacement_profile_id');
            $table->dropConstrainedForeignId('retired_by');
            $table->dropColumn(['retired_at', 'retirement_reason']);
        });
    }
};
