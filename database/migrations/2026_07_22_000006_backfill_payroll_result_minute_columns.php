<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const MINUTE_COLUMNS = [
        'worked_minutes',
        'scheduled_minutes',
        'recognized_minutes',
        'detected_overtime_minutes',
        'approved_overtime_minutes',
        'ordinary_minutes',
        'extra_25_minutes',
        'extra_50_minutes',
        'extra_75_minutes',
        'extra_100_minutes',
    ];

    public function up(): void
    {
        $missingColumns = [];

        foreach (self::MINUTE_COLUMNS as $column) {
            if (! Schema::hasColumn('payroll_results', $column)) {
                $missingColumns[] = $column;
            }
        }

        if ($missingColumns === []) {
            return;
        }

        Schema::table('payroll_results', function (Blueprint $table) use ($missingColumns): void {
            foreach ($missingColumns as $column) {
                $table->unsignedInteger($column)->default(0);
            }
        });

        DB::table('payroll_results')
            ->orderBy('id')
            ->chunkById(200, function ($results): void {
                foreach ($results as $result) {
                    $worked = (int) round((float) $result->worked_hours * 60);
                    $ordinary = (int) round((float) $result->ordinary_hours * 60);
                    $extra25 = (int) round((float) $result->extra_25_hours * 60);
                    $extra50 = (int) round((float) $result->extra_50_hours * 60);
                    $extra75 = (int) round((float) $result->extra_75_hours * 60);
                    $extra100 = (int) round((float) $result->extra_100_hours * 60);
                    $extra = $extra25 + $extra50 + $extra75 + $extra100;

                    DB::table('payroll_results')->where('id', $result->id)->update([
                        'worked_minutes' => $worked,
                        'scheduled_minutes' => $worked,
                        'recognized_minutes' => $ordinary + $extra,
                        'detected_overtime_minutes' => $extra,
                        'approved_overtime_minutes' => $extra,
                        'ordinary_minutes' => $ordinary,
                        'extra_25_minutes' => $extra25,
                        'extra_50_minutes' => $extra50,
                        'extra_75_minutes' => $extra75,
                        'extra_100_minutes' => $extra100,
                    ]);
                }
            });
    }

    public function down(): void
    {
        // compatibility migration: preserve column data in rollback-safe way.
    }
};
