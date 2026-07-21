<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_schedules', function (Blueprint $table): void {
            $table->json('banding_json')->nullable()->after('base_ordinary_hours');
        });
    }

    public function down(): void
    {
        Schema::table('work_schedules', function (Blueprint $table): void {
            $table->dropColumn('banding_json');
        });
    }
};
