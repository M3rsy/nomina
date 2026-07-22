<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('justified_absences', function (Blueprint $table) {
            $table->string('schedule_fingerprint', 64)->nullable();
            $table->dateTime('scheduled_start')->nullable();
            $table->dateTime('scheduled_end')->nullable();
            $table->unsignedInteger('scheduled_minutes')->nullable();
            $table->json('rate_minutes')->nullable();
            $table->json('metadata')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('justified_absences', function (Blueprint $table) {
            $table->dropColumn([
                'schedule_fingerprint',
                'scheduled_start',
                'scheduled_end',
                'scheduled_minutes',
                'rate_minutes',
                'metadata',
            ]);
        });
    }
};
