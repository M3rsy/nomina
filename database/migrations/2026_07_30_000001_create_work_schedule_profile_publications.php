<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $assignments = DB::table('employee_schedule_assignments as assignment')
            ->leftJoin('work_schedule_profiles as profile', 'profile.id', '=', 'assignment.work_schedule_profile_id')
            ->select('assignment.*', 'profile.company_id as profile_company_id', 'profile.profile_key')
            ->orderBy('assignment.company_id')->orderBy('assignment.employee_id')
            ->orderBy('assignment.effective_from')->orderBy('assignment.id')->get();
        $this->validateAssignments($assignments);

        Schema::table('work_schedule_profiles', function (Blueprint $table): void {
            $table->unique(
                ['company_id', 'profile_key', 'id'],
                'work_schedule_profiles_company_key_id_unique',
            );
        });

        Schema::create('work_schedule_profile_publications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('profile_key');
            $table->unsignedBigInteger('profile_id');
            $table->string('payroll_policy_key', 32);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->char('definition_hash', 64);
            $table->char('request_key', 64);
            $table->char('payload_hash', 64);
            $table->text('reason');
            $table->foreignId('published_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'profile_key', 'id'], 'work_schedule_publications_company_key_id_unique');
            $table->unique(['company_id', 'profile_key', 'request_key'], 'work_schedule_publications_request_unique');
            $table->foreign(
                ['company_id', 'profile_key', 'profile_id'],
                'work_schedule_publications_profile_foreign',
            )->references(['company_id', 'profile_key', 'id'])->on('work_schedule_profiles')->restrictOnDelete();
            $table->index(
                ['company_id', 'profile_id', 'effective_from', 'effective_to'],
                'work_schedule_publications_effective_idx',
            );
        });

        $this->installPostgreSqlConstraints();
        $this->backfill($assignments);
    }

    public function down(): void
    {
        Schema::dropIfExists('work_schedule_profile_publications');
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS reject_work_schedule_publication_mutation()');
        }

        Schema::table('work_schedule_profiles', function (Blueprint $table): void {
            $table->dropUnique('work_schedule_profiles_company_key_id_unique');
        });
    }

    private function installPostgreSqlConstraints(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE EXTENSION IF NOT EXISTS btree_gist;
            ALTER TABLE work_schedule_profile_publications
                ADD CONSTRAINT work_schedule_publications_values_check CHECK (
                    effective_to IS NULL OR effective_to > effective_from
                ),
                ADD CONSTRAINT work_schedule_publications_text_check CHECK (
                    btrim(profile_key) <> '' AND btrim(reason) <> ''
                    AND definition_hash ~ '^[a-f0-9]{64}$' AND request_key ~ '^[a-f0-9]{64}$'
                    AND payload_hash ~ '^[a-f0-9]{64}$'
                ),
                ADD CONSTRAINT work_schedule_publications_policy_check CHECK (
                    payroll_policy_key IN ('schedule-overlap-v1', 'duration-first-v2')
                    AND (payroll_policy_key <> 'duration-first-v2' OR (profile_key = 'general' AND published_by IS NOT NULL))
                ),
                ADD CONSTRAINT work_schedule_publications_profile_overlap_exclude
                    EXCLUDE USING gist (company_id WITH =, profile_id WITH =,
                        daterange(effective_from, COALESCE(effective_to, 'infinity'::date), '[)') WITH &&),
                ADD CONSTRAINT work_schedule_publications_v2_key_overlap_exclude
                    EXCLUDE USING gist (company_id WITH =, profile_key WITH =,
                        daterange(effective_from, COALESCE(effective_to, 'infinity'::date), '[)') WITH &&)
                    WHERE (payroll_policy_key = 'duration-first-v2');
            DROP FUNCTION IF EXISTS reject_work_schedule_publication_mutation() CASCADE;
            CREATE FUNCTION reject_work_schedule_publication_mutation() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'UPDATE' AND OLD.effective_to IS NULL AND NEW.effective_to IS NOT NULL
                    AND NEW.effective_to > OLD.effective_from
                    AND (to_jsonb(NEW) - ARRAY['effective_to', 'updated_at'])
                        = (to_jsonb(OLD) - ARRAY['effective_to', 'updated_at']) THEN
                    RETURN NEW;
                END IF;
                RAISE EXCEPTION 'work schedule profile publications are immutable' USING ERRCODE = '23514';
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER work_schedule_publications_immutable
                BEFORE UPDATE OR DELETE ON work_schedule_profile_publications
                FOR EACH ROW EXECUTE FUNCTION reject_work_schedule_publication_mutation();
            SQL);
    }

    private function validateAssignments($assignments): void
    {
        foreach ($assignments as $assignment) {
            if ($assignment->profile_company_id === null
                || (int) $assignment->company_id !== (int) $assignment->profile_company_id
                || ($assignment->effective_to !== null && $assignment->effective_to < $assignment->effective_from)) {
                throw new RuntimeException('Cannot publish legacy payroll policy identity: invalid assignment history.');
            }
        }

        foreach ($assignments->groupBy(fn (object $row): string => "{$row->company_id}|{$row->employee_id}") as $employeeAssignments) {
            $previousEnd = null;
            foreach ($employeeAssignments as $index => $assignment) {
                if ($index > 0 && ($previousEnd === null || $assignment->effective_from <= $previousEnd)) {
                    throw new RuntimeException('Cannot publish legacy payroll policy identity: overlapping assignment history.');
                }
                $previousEnd = $assignment->effective_to;
            }
        }
    }

    private function backfill($assignments): void
    {
        $now = now();
        $assignmentsByProfile = $assignments
            ->groupBy(fn (object $row): string => "{$row->company_id}|{$row->work_schedule_profile_id}");
        $futureFrom = $now->toDateString();
        $activeProfiles = DB::table('work_schedule_profiles')
            ->where('is_active', true)->whereNull('retired_at')
            ->orderBy('company_id')->orderBy('id')
            ->get(['id', 'company_id', 'profile_key']);

        foreach ($activeProfiles as $profile) {
            $key = "{$profile->company_id}|{$profile->id}";
            $profileAssignments = $assignmentsByProfile->get($key, collect());
            $profileAssignments->push((object) [
                'id' => PHP_INT_MAX, 'company_id' => $profile->company_id,
                'work_schedule_profile_id' => $profile->id, 'profile_key' => $profile->profile_key,
                'effective_from' => $futureFrom, 'effective_to' => null,
            ]);
            $assignmentsByProfile->put($key, $profileAssignments);
        }

        foreach ($assignmentsByProfile as $profileAssignments) {
            $ranges = [];
            foreach ($profileAssignments->sortBy(fn (object $row): string => "{$row->effective_from}|{$row->effective_to}|{$row->id}") as $assignment) {
                $end = $assignment->effective_to === null
                    ? null
                    : date('Y-m-d', strtotime($assignment->effective_to.' +1 day'));
                $last = array_key_last($ranges);
                if ($last !== null && ($ranges[$last]['effective_to'] === null || $assignment->effective_from <= $ranges[$last]['effective_to'])) {
                    if ($end === null || ($ranges[$last]['effective_to'] !== null && $end > $ranges[$last]['effective_to'])) {
                        $ranges[$last]['effective_to'] = $end;
                    }

                    continue;
                }
                $ranges[] = ['effective_from' => $assignment->effective_from, 'effective_to' => $end];
            }

            $profile = $profileAssignments->first();
            foreach ($ranges as $range) {
                $identity = implode('|', [$profile->company_id, $profile->profile_key, $profile->work_schedule_profile_id, ...array_values($range)]);
                DB::table('work_schedule_profile_publications')->insert([
                    'company_id' => $profile->company_id, 'profile_key' => $profile->profile_key,
                    'profile_id' => $profile->work_schedule_profile_id, 'payroll_policy_key' => 'schedule-overlap-v1',
                    ...$range, 'definition_hash' => hash('sha256', "legacy-definition|{$identity}"),
                    'request_key' => hash('sha256', "legacy-request|{$identity}"),
                    'payload_hash' => hash('sha256', "legacy-payload|{$identity}"),
                    'reason' => 'Legacy assignment coverage backfill', 'published_by' => null,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
    }
};
