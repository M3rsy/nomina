<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_review_entries', function (Blueprint $table): void {
            $table->unsignedInteger('rate_ordinary_minutes')->default(0);
            $table->unsignedInteger('rate_extra25_minutes')->default(0);
            $table->unsignedInteger('rate_extra50_minutes')->default(0);
            $table->unsignedInteger('rate_extra75_minutes')->default(0);
            $table->unsignedInteger('rate_extra100_minutes')->default(0);
        });

        DB::table('payroll_review_entries')->orderBy('id')->chunkById(500, function ($entries): void {
            foreach ($entries as $entry) {
                $payload = is_string($entry->payload) ? json_decode($entry->payload, true) : (array) $entry->payload;
                $rateMinutes = $payload['segment']['rate_minutes'] ?? [];

                DB::table('payroll_review_entries')->where('id', $entry->id)->update([
                    'rate_ordinary_minutes' => max(0, (int) ($rateMinutes['ordinary'] ?? 0)),
                    'rate_extra25_minutes' => max(0, (int) ($rateMinutes['extra25'] ?? 0)),
                    'rate_extra50_minutes' => max(0, (int) ($rateMinutes['extra50'] ?? 0)),
                    'rate_extra75_minutes' => max(0, (int) ($rateMinutes['extra75'] ?? 0)),
                    'rate_extra100_minutes' => max(0, (int) ($rateMinutes['extra100'] ?? 0)),
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('payroll_review_entries', function (Blueprint $table): void {
            $table->dropColumn([
                'rate_ordinary_minutes',
                'rate_extra25_minutes',
                'rate_extra50_minutes',
                'rate_extra75_minutes',
                'rate_extra100_minutes',
            ]);
        });
    }
};
