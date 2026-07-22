<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_schedule_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('profile_key');
            $table->string('name');
            $table->unsignedInteger('version')->default(1);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('change_reason')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'profile_key', 'version']);
        });

        Schema::table('work_schedules', function (Blueprint $table): void {
            $table->foreignId('work_schedule_profile_id')
                ->nullable()
                ->after('company_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->time('start_time')->nullable()->after('base_ordinary_hours');
            $table->time('end_time')->nullable()->after('start_time');
        });

        $now = now();
        $defaults = collect([
            ['day_of_week' => 1, 'is_working_day' => true, 'base_ordinary_hours' => 8.00, 'start_time' => '06:00', 'end_time' => '14:00', 'notes' => null],
            ['day_of_week' => 2, 'is_working_day' => true, 'base_ordinary_hours' => 8.00, 'start_time' => '06:00', 'end_time' => '14:00', 'notes' => null],
            ['day_of_week' => 3, 'is_working_day' => true, 'base_ordinary_hours' => 8.00, 'start_time' => '06:00', 'end_time' => '14:00', 'notes' => null],
            ['day_of_week' => 4, 'is_working_day' => true, 'base_ordinary_hours' => 8.00, 'start_time' => '06:00', 'end_time' => '14:00', 'notes' => null],
            ['day_of_week' => 5, 'is_working_day' => true, 'base_ordinary_hours' => 8.00, 'start_time' => '06:00', 'end_time' => '14:00', 'notes' => null],
            ['day_of_week' => 6, 'is_working_day' => true, 'base_ordinary_hours' => 4.00, 'start_time' => '08:00', 'end_time' => '12:00', 'notes' => null],
            ['day_of_week' => 0, 'is_working_day' => false, 'base_ordinary_hours' => 0.00, 'start_time' => null, 'end_time' => null, 'notes' => null],
        ])->keyBy('day_of_week');

        DB::table('companies')->orderBy('id')->each(function (object $company) use ($defaults, $now): void {
            $profileId = DB::table('work_schedule_profiles')->insertGetId([
                'company_id' => $company->id,
                'profile_key' => 'general',
                'name' => 'Jornada general',
                'version' => 1,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            foreach ($defaults as $day => $default) {
                $existing = DB::table('work_schedules')
                    ->where('company_id', $company->id)
                    ->where('day_of_week', $day)
                    ->first();

                if ($existing !== null) {
                    $isWorkingDay = (bool) $existing->is_working_day;
                    $startTime = $isWorkingDay ? ($day === 6 ? '08:00' : '06:00') : null;
                    $endTime = $startTime === null
                        ? null
                        : CarbonImmutable::createFromFormat('H:i', $startTime)
                            ->addMinutes((int) round((float) $existing->base_ordinary_hours * 60))
                            ->format('H:i');

                    DB::table('work_schedules')->where('id', $existing->id)->update([
                        'work_schedule_profile_id' => $profileId,
                        'start_time' => $startTime,
                        'end_time' => $endTime,
                        'updated_at' => $now,
                    ]);

                    continue;
                }

                DB::table('work_schedules')->insert([
                    'company_id' => $company->id,
                    'work_schedule_profile_id' => $profileId,
                    'day_of_week' => $day,
                    'is_working_day' => $default['is_working_day'],
                    'base_ordinary_hours' => $default['base_ordinary_hours'],
                    'banding_json' => null,
                    'start_time' => $default['start_time'],
                    'end_time' => $default['end_time'],
                    'notes' => $default['notes'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        });

        Schema::table('work_schedules', function (Blueprint $table): void {
            $table->dropUnique(['company_id', 'day_of_week']);
            $table->unique(['work_schedule_profile_id', 'day_of_week']);
            $table->index(['company_id', 'day_of_week']);
        });
    }

    public function down(): void
    {
        DB::table('work_schedules')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (object $schedule): string => $schedule->company_id.':'.$schedule->day_of_week)
            ->each(function ($versions): void {
                DB::table('work_schedules')->whereIn('id', $versions->skip(1)->pluck('id'))->delete();
            });

        Schema::table('work_schedules', function (Blueprint $table): void {
            $table->dropUnique(['work_schedule_profile_id', 'day_of_week']);
            $table->dropIndex(['company_id', 'day_of_week']);
            $table->unique(['company_id', 'day_of_week']);
            $table->dropConstrainedForeignId('work_schedule_profile_id');
            $table->dropColumn(['start_time', 'end_time']);
        });

        Schema::dropIfExists('work_schedule_profiles');
    }
};
