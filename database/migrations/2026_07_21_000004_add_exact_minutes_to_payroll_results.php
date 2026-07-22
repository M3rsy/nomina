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
        Schema::table('payroll_results', function (Blueprint $table) {
            $table->decimal('extra_25_hours', 8, 2)->default(0)->change();
            $table->decimal('extra_50_hours', 8, 2)->default(0)->change();
            $table->decimal('extra_75_hours', 8, 2)->default(0)->change();
            $table->decimal('extra_100_hours', 8, 2)->default(0)->change();

            foreach (self::MINUTE_COLUMNS as $column) {
                $table->unsignedInteger($column)->default(0);
            }
        });

        DB::table('payroll_results')->orderBy('id')->chunkById(200, function ($results): void {
            foreach ($results as $result) {
                $ordinary = (int) round((float) $result->ordinary_hours * 60);
                $extra25 = (int) round((float) $result->extra_25_hours * 60);
                $extra50 = (int) round((float) $result->extra_50_hours * 60);
                $extra75 = (int) round((float) $result->extra_75_hours * 60);
                $extra100 = (int) round((float) $result->extra_100_hours * 60);
                $overtime = $extra25 + $extra50 + $extra75 + $extra100;
                $metadata = json_decode($result->metadata ?? '[]', true) ?: [];

                if ($overtime > 0) {
                    $metadata['exact_minutes_migration'] = [
                        'legacy_paid_overtime_preserved_as_approved' => true,
                    ];
                }

                DB::table('payroll_results')->where('id', $result->id)->update([
                    'worked_minutes' => (int) round((float) $result->worked_hours * 60),
                    'scheduled_minutes' => (int) round((float) $result->worked_hours * 60),
                    'recognized_minutes' => $ordinary + $overtime,
                    'detected_overtime_minutes' => $overtime,
                    'approved_overtime_minutes' => $overtime,
                    'ordinary_minutes' => $ordinary,
                    'extra_25_minutes' => $extra25,
                    'extra_50_minutes' => $extra50,
                    'extra_75_minutes' => $extra75,
                    'extra_100_minutes' => $extra100,
                    'metadata' => $metadata === [] ? null : json_encode($metadata),
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('payroll_results', function (Blueprint $table) {
            $table->dropColumn(self::MINUTE_COLUMNS);
            $table->integer('extra_25_hours')->default(0)->change();
            $table->integer('extra_50_hours')->default(0)->change();
            $table->integer('extra_75_hours')->default(0)->change();
            $table->integer('extra_100_hours')->default(0)->change();
        });
    }
};
