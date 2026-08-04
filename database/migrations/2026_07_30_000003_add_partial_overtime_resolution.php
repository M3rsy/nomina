<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('overtime_decisions', function (Blueprint $table): void {
            $table->unsignedSmallInteger('record_version')->default(1)->after('id');
            $table->string('resolution_kind', 20)->nullable();
            foreach (['approved_starts_at', 'approved_ends_at', 'rejected_before_starts_at', 'rejected_before_ends_at', 'rejected_after_starts_at', 'rejected_after_ends_at'] as $column) {
                $table->dateTime($column)->nullable();
            }
            foreach (['approved_minutes', 'rejected_minutes', 'rejected_before_minutes', 'rejected_after_minutes'] as $column) {
                $table->unsignedInteger($column)->nullable();
            }
            $table->json('approved_rate_minutes')->nullable();
            $table->json('rejected_rate_minutes')->nullable();
            $table->char('resolution_hash', 64)->nullable();
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE overtime_decisions ADD CONSTRAINT overtime_decisions_versioned_resolution_check CHECK (
                (record_version = 1
                    AND resolution_kind IS NULL
                    AND approved_starts_at IS NULL AND approved_ends_at IS NULL
                    AND rejected_before_starts_at IS NULL AND rejected_before_ends_at IS NULL
                    AND rejected_after_starts_at IS NULL AND rejected_after_ends_at IS NULL
                    AND approved_minutes IS NULL AND rejected_minutes IS NULL
                    AND rejected_before_minutes IS NULL AND rejected_after_minutes IS NULL
                    AND approved_rate_minutes IS NULL AND rejected_rate_minutes IS NULL
                    AND resolution_hash IS NULL)
                OR
                (record_version = 2
                    AND segment_kind = 'post_quota_overtime'
                    AND candidate_key ~ '^[a-f0-9]{64}$' AND fingerprint ~ '^[a-f0-9]{64}$'
                    AND decided_by IS NOT NULL AND btrim(reason) <> ''
                    AND starts_at = date_trunc('minute', starts_at)
                    AND ends_at = date_trunc('minute', ends_at) AND ends_at > starts_at
                    AND EXTRACT(EPOCH FROM ends_at - starts_at) = minutes * 60
                    AND resolution_kind IS NOT NULL AND resolution_hash IS NOT NULL
                    AND resolution_hash ~ '^[a-f0-9]{64}$'
                    AND resolution_kind IN ('whole_approve', 'whole_reject', 'partial')
                    AND approved_minutes IS NOT NULL AND rejected_minutes IS NOT NULL
                    AND rejected_before_minutes IS NOT NULL AND rejected_after_minutes IS NOT NULL
                    AND approved_rate_minutes IS NOT NULL AND rejected_rate_minutes IS NOT NULL
                    AND (rate_minutes::jsonb->>'ordinary') IS NOT NULL AND (rate_minutes::jsonb->>'extra25') IS NOT NULL
                    AND (rate_minutes::jsonb->>'extra50') IS NOT NULL AND (rate_minutes::jsonb->>'extra75') IS NOT NULL
                    AND (rate_minutes::jsonb->>'extra100') IS NOT NULL
                    AND (approved_rate_minutes::jsonb->>'ordinary') IS NOT NULL AND (approved_rate_minutes::jsonb->>'extra25') IS NOT NULL
                    AND (approved_rate_minutes::jsonb->>'extra50') IS NOT NULL AND (approved_rate_minutes::jsonb->>'extra75') IS NOT NULL
                    AND (approved_rate_minutes::jsonb->>'extra100') IS NOT NULL
                    AND (rejected_rate_minutes::jsonb->>'ordinary') IS NOT NULL AND (rejected_rate_minutes::jsonb->>'extra25') IS NOT NULL
                    AND (rejected_rate_minutes::jsonb->>'extra50') IS NOT NULL AND (rejected_rate_minutes::jsonb->>'extra75') IS NOT NULL
                    AND (rejected_rate_minutes::jsonb->>'extra100') IS NOT NULL
                    AND rate_minutes::jsonb = jsonb_build_object(
                        'ordinary', (rate_minutes::jsonb->>'ordinary')::integer,
                        'extra25', (rate_minutes::jsonb->>'extra25')::integer,
                        'extra50', (rate_minutes::jsonb->>'extra50')::integer,
                        'extra75', (rate_minutes::jsonb->>'extra75')::integer,
                        'extra100', (rate_minutes::jsonb->>'extra100')::integer)
                    AND approved_minutes >= 0 AND rejected_minutes >= 0
                    AND rejected_before_minutes >= 0 AND rejected_after_minutes >= 0
                    AND approved_minutes + rejected_minutes = minutes
                    AND (resolution_kind <> 'partial' OR rejected_before_minutes + rejected_after_minutes = rejected_minutes)
                    AND approved_rate_minutes::jsonb = jsonb_build_object(
                        'ordinary', (approved_rate_minutes::jsonb->>'ordinary')::integer,
                        'extra25', (approved_rate_minutes::jsonb->>'extra25')::integer,
                        'extra50', (approved_rate_minutes::jsonb->>'extra50')::integer,
                        'extra75', (approved_rate_minutes::jsonb->>'extra75')::integer,
                        'extra100', (approved_rate_minutes::jsonb->>'extra100')::integer)
                    AND rejected_rate_minutes::jsonb = jsonb_build_object(
                        'ordinary', (rejected_rate_minutes::jsonb->>'ordinary')::integer,
                        'extra25', (rejected_rate_minutes::jsonb->>'extra25')::integer,
                        'extra50', (rejected_rate_minutes::jsonb->>'extra50')::integer,
                        'extra75', (rejected_rate_minutes::jsonb->>'extra75')::integer,
                        'extra100', (rejected_rate_minutes::jsonb->>'extra100')::integer)
                    AND (approved_rate_minutes::jsonb->>'ordinary')::integer >= 0 AND (approved_rate_minutes::jsonb->>'extra25')::integer >= 0
                    AND (approved_rate_minutes::jsonb->>'extra50')::integer >= 0 AND (approved_rate_minutes::jsonb->>'extra75')::integer >= 0 AND (approved_rate_minutes::jsonb->>'extra100')::integer >= 0
                    AND (rejected_rate_minutes::jsonb->>'ordinary')::integer >= 0 AND (rejected_rate_minutes::jsonb->>'extra25')::integer >= 0
                    AND (rejected_rate_minutes::jsonb->>'extra50')::integer >= 0 AND (rejected_rate_minutes::jsonb->>'extra75')::integer >= 0 AND (rejected_rate_minutes::jsonb->>'extra100')::integer >= 0
                    AND (approved_rate_minutes::jsonb->>'ordinary')::integer + (approved_rate_minutes::jsonb->>'extra25')::integer
                        + (approved_rate_minutes::jsonb->>'extra50')::integer + (approved_rate_minutes::jsonb->>'extra75')::integer
                        + (approved_rate_minutes::jsonb->>'extra100')::integer = approved_minutes
                    AND (rejected_rate_minutes::jsonb->>'ordinary')::integer + (rejected_rate_minutes::jsonb->>'extra25')::integer
                        + (rejected_rate_minutes::jsonb->>'extra50')::integer + (rejected_rate_minutes::jsonb->>'extra75')::integer
                        + (rejected_rate_minutes::jsonb->>'extra100')::integer = rejected_minutes
                    AND (rate_minutes::jsonb->>'ordinary')::integer = (approved_rate_minutes::jsonb->>'ordinary')::integer + (rejected_rate_minutes::jsonb->>'ordinary')::integer
                    AND (rate_minutes::jsonb->>'extra25')::integer = (approved_rate_minutes::jsonb->>'extra25')::integer + (rejected_rate_minutes::jsonb->>'extra25')::integer
                    AND (rate_minutes::jsonb->>'extra50')::integer = (approved_rate_minutes::jsonb->>'extra50')::integer + (rejected_rate_minutes::jsonb->>'extra50')::integer
                    AND (rate_minutes::jsonb->>'extra75')::integer = (approved_rate_minutes::jsonb->>'extra75')::integer + (rejected_rate_minutes::jsonb->>'extra75')::integer
                    AND (rate_minutes::jsonb->>'extra100')::integer = (approved_rate_minutes::jsonb->>'extra100')::integer + (rejected_rate_minutes::jsonb->>'extra100')::integer
                    AND CASE resolution_kind
                        WHEN 'whole_approve' THEN decision = 'approved'
                            AND approved_starts_at IS NOT NULL AND approved_ends_at IS NOT NULL
                            AND approved_starts_at = starts_at AND approved_ends_at = ends_at
                            AND approved_minutes = minutes AND rejected_minutes = 0
                            AND rejected_before_starts_at IS NULL AND rejected_before_ends_at IS NULL
                            AND rejected_after_starts_at IS NULL AND rejected_after_ends_at IS NULL
                        WHEN 'whole_reject' THEN decision = 'rejected'
                            AND approved_starts_at IS NULL AND approved_ends_at IS NULL
                            AND approved_minutes = 0 AND rejected_minutes = minutes
                            AND rejected_before_starts_at IS NULL AND rejected_before_ends_at IS NULL
                            AND rejected_after_starts_at IS NULL AND rejected_after_ends_at IS NULL
                        WHEN 'partial' THEN decision = 'approved' AND batch_item_id IS NULL
                            AND approved_starts_at IS NOT NULL AND approved_ends_at IS NOT NULL
                            AND approved_starts_at >= starts_at AND approved_ends_at <= ends_at
                            AND approved_starts_at < approved_ends_at
                            AND approved_starts_at = date_trunc('minute', approved_starts_at)
                            AND approved_ends_at = date_trunc('minute', approved_ends_at)
                            AND approved_minutes > 0 AND approved_minutes < minutes
                            AND EXTRACT(EPOCH FROM approved_ends_at - approved_starts_at) = approved_minutes * 60
                            AND ((rejected_before_minutes = 0 AND rejected_before_starts_at IS NULL AND rejected_before_ends_at IS NULL)
                                OR (rejected_before_minutes > 0 AND rejected_before_starts_at IS NOT NULL AND rejected_before_ends_at IS NOT NULL
                                    AND rejected_before_starts_at = starts_at AND rejected_before_ends_at = approved_starts_at
                                    AND EXTRACT(EPOCH FROM rejected_before_ends_at - rejected_before_starts_at) = rejected_before_minutes * 60))
                            AND ((rejected_after_minutes = 0 AND rejected_after_starts_at IS NULL AND rejected_after_ends_at IS NULL)
                                OR (rejected_after_minutes > 0 AND rejected_after_starts_at IS NOT NULL AND rejected_after_ends_at IS NOT NULL
                                    AND rejected_after_starts_at = approved_ends_at AND rejected_after_ends_at = ends_at
                                    AND EXTRACT(EPOCH FROM rejected_after_ends_at - rejected_after_starts_at) = rejected_after_minutes * 60))
                    END)
            );

            CREATE UNIQUE INDEX overtime_decisions_v2_root_context_unique
                ON overtime_decisions (company_id, pay_period_id, employee_id, work_date, candidate_key)
                WHERE record_version = 2 AND supersedes_id IS NULL;

            CREATE FUNCTION enforce_overtime_decision_append_only() RETURNS trigger AS $$
            DECLARE parent overtime_decisions%ROWTYPE;
            BEGIN
                IF TG_OP <> 'INSERT' THEN
                    RAISE EXCEPTION 'overtime decisions are append-only' USING ERRCODE = '23514';
                END IF;
                IF NEW.record_version = 2 AND NEW.supersedes_id IS NOT NULL THEN
                    SELECT * INTO parent FROM overtime_decisions WHERE id = NEW.supersedes_id FOR UPDATE;
                    IF NOT FOUND OR parent.record_version <> 2
                        OR parent.company_id <> NEW.company_id OR parent.pay_period_id <> NEW.pay_period_id
                        OR parent.employee_id <> NEW.employee_id OR parent.work_date <> NEW.work_date
                        OR parent.candidate_key <> NEW.candidate_key OR parent.fingerprint <> NEW.fingerprint
                        OR parent.segment_kind <> NEW.segment_kind OR parent.starts_at <> NEW.starts_at
                        OR parent.ends_at <> NEW.ends_at OR parent.minutes <> NEW.minutes
                        OR parent.rate_minutes::jsonb <> NEW.rate_minutes::jsonb
                        OR EXISTS (SELECT 1 FROM overtime_decisions WHERE supersedes_id = parent.id) THEN
                        RAISE EXCEPTION 'decision must supersede the current matching V2 candidate' USING ERRCODE = '23514';
                    END IF;
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER overtime_decisions_append_only
                BEFORE INSERT OR UPDATE OR DELETE ON overtime_decisions
                FOR EACH ROW EXECUTE FUNCTION enforce_overtime_decision_append_only();
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS overtime_decisions_append_only ON overtime_decisions');
            DB::unprepared('DROP FUNCTION IF EXISTS enforce_overtime_decision_append_only()');
            DB::unprepared('DROP INDEX IF EXISTS overtime_decisions_v2_root_context_unique');
            DB::unprepared('ALTER TABLE overtime_decisions DROP CONSTRAINT IF EXISTS overtime_decisions_versioned_resolution_check');
        }
    }
};
