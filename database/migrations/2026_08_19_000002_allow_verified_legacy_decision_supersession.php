<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION consume_verified_attendance_compatible_supersession(
                expected_type text,
                expected_parent_id bigint,
                expected_parent_key text,
                expected_parent_fingerprint text,
                expected_child_record_version integer,
                expected_child_key text,
                expected_child_fingerprint text,
                expected_company_id bigint,
                expected_pay_period_id bigint,
                expected_employee_id bigint,
                expected_work_date date
            ) RETURNS boolean AS $$
            DECLARE capability jsonb;
            BEGIN
                BEGIN
                    capability := NULLIF(
                        current_setting('nomina.attendance_compatible_supersession', true),
                        ''
                    )::jsonb;
                EXCEPTION WHEN OTHERS THEN
                    capability := NULL;
                END;

                PERFORM set_config('nomina.attendance_compatible_supersession', '', true);

                RETURN capability IS NOT NULL
                    AND capability->>'decision_type' IS NOT DISTINCT FROM expected_type
                    AND capability->>'parent_id' IS NOT DISTINCT FROM expected_parent_id::text
                    AND capability->>'parent_key' IS NOT DISTINCT FROM expected_parent_key
                    AND capability->>'parent_fingerprint' IS NOT DISTINCT FROM expected_parent_fingerprint
                    AND capability->>'child_record_version' IS NOT DISTINCT FROM expected_child_record_version::text
                    AND capability->>'child_key' IS NOT DISTINCT FROM expected_child_key
                    AND capability->>'child_fingerprint' IS NOT DISTINCT FROM expected_child_fingerprint
                    AND capability->>'company_id' IS NOT DISTINCT FROM expected_company_id::text
                    AND capability->>'pay_period_id' IS NOT DISTINCT FROM expected_pay_period_id::text
                    AND capability->>'employee_id' IS NOT DISTINCT FROM expected_employee_id::text
                    AND capability->>'work_date' IS NOT DISTINCT FROM expected_work_date::text;
            END;
            $$ LANGUAGE plpgsql VOLATILE;

            CREATE OR REPLACE FUNCTION enforce_overtime_decision_append_only() RETURNS trigger AS $$
            DECLARE
                parent overtime_decisions%ROWTYPE;
                verified_transition boolean;
            BEGIN
                IF TG_OP <> 'INSERT' THEN
                    RAISE EXCEPTION 'overtime decisions are append-only' USING ERRCODE = '23514';
                END IF;
                IF NEW.supersedes_id IS NOT NULL THEN
                    SELECT * INTO parent FROM overtime_decisions WHERE id = NEW.supersedes_id FOR UPDATE;
                    IF NOT FOUND
                        OR parent.company_id <> NEW.company_id OR parent.pay_period_id <> NEW.pay_period_id
                        OR parent.employee_id <> NEW.employee_id OR parent.work_date <> NEW.work_date
                        OR EXISTS (SELECT 1 FROM overtime_decisions WHERE supersedes_id = parent.id) THEN
                        RAISE EXCEPTION 'decision must supersede the current matching candidate' USING ERRCODE = '23514';
                    END IF;

                    IF NOT (
                        parent.record_version = NEW.record_version
                        AND parent.candidate_key = NEW.candidate_key
                        AND parent.fingerprint = NEW.fingerprint
                        AND parent.segment_kind = NEW.segment_kind
                        AND parent.starts_at IS NOT DISTINCT FROM NEW.starts_at
                        AND parent.ends_at IS NOT DISTINCT FROM NEW.ends_at
                        AND parent.minutes = NEW.minutes
                        AND parent.rate_minutes::jsonb = NEW.rate_minutes::jsonb
                    ) THEN
                        verified_transition := consume_verified_attendance_compatible_supersession(
                            'overtime', parent.id, parent.candidate_key, parent.fingerprint,
                            NEW.record_version, NEW.candidate_key, NEW.fingerprint, NEW.company_id,
                            NEW.pay_period_id, NEW.employee_id, NEW.work_date
                        );
                        IF NOT verified_transition THEN
                            RAISE EXCEPTION 'verified compatible overtime supersession authorization is required' USING ERRCODE = '23514';
                        END IF;
                        IF parent.record_version = NEW.record_version THEN
                            IF parent.segment_kind <> NEW.segment_kind
                                OR parent.starts_at IS DISTINCT FROM NEW.starts_at
                                OR parent.ends_at IS DISTINCT FROM NEW.ends_at
                                OR parent.minutes <> NEW.minutes
                                OR parent.rate_minutes::jsonb <> NEW.rate_minutes::jsonb THEN
                                RAISE EXCEPTION 'compatible overtime identities must preserve the exact candidate' USING ERRCODE = '23514';
                            END IF;
                        ELSIF NOT (
                            parent.record_version = 1 AND NEW.record_version = 2
                            AND parent.segment_kind = 'post_shift'
                            AND NEW.segment_kind = 'post_quota_overtime'
                            AND parent.starts_at IS NOT DISTINCT FROM NEW.starts_at
                            AND parent.ends_at IS NOT DISTINCT FROM NEW.ends_at
                            AND parent.minutes = NEW.minutes
                            AND parent.rate_minutes::jsonb = NEW.rate_minutes::jsonb
                        ) THEN
                            RAISE EXCEPTION 'unsupported compatible overtime predecessor transition' USING ERRCODE = '23514';
                        END IF;
                    END IF;
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION enforce_attendance_exception_append_only() RETURNS trigger AS $$
            DECLARE
                parent attendance_exceptions%ROWTYPE;
                verified_transition boolean;
            BEGIN
                IF TG_OP <> 'INSERT' THEN
                    RAISE EXCEPTION 'attendance exceptions are append-only' USING ERRCODE = '23514';
                END IF;

                IF NEW.supersedes_id IS NOT NULL THEN
                    SELECT * INTO parent FROM attendance_exceptions WHERE id = NEW.supersedes_id FOR UPDATE;
                    IF NOT FOUND
                        OR parent.company_id <> NEW.company_id OR parent.pay_period_id <> NEW.pay_period_id
                        OR parent.employee_id <> NEW.employee_id OR parent.work_date <> NEW.work_date
                        OR EXISTS (SELECT 1 FROM attendance_exceptions WHERE supersedes_id = parent.id)
                        OR (NEW.decision = 'revoked' AND parent.decision <> 'granted')
                        OR (NEW.decision IN ('granted', 'rejected') AND parent.decision <> 'revoked') THEN
                        RAISE EXCEPTION 'decision must supersede the current matching deficit state' USING ERRCODE = '23514';
                    END IF;

                    IF NOT (
                        parent.record_version = NEW.record_version
                        AND parent.deficit_key = NEW.deficit_key
                        AND parent.fingerprint = NEW.fingerprint
                        AND parent.segment_kind = NEW.segment_kind
                        AND parent.starts_at IS NOT DISTINCT FROM NEW.starts_at
                        AND parent.ends_at IS NOT DISTINCT FROM NEW.ends_at
                        AND parent.minutes = NEW.minutes
                        AND parent.rate_minutes::jsonb = NEW.rate_minutes::jsonb
                    ) THEN
                        verified_transition := consume_verified_attendance_compatible_supersession(
                            'attendance_exception', parent.id, parent.deficit_key, parent.fingerprint,
                            NEW.record_version, NEW.deficit_key, NEW.fingerprint, NEW.company_id,
                            NEW.pay_period_id, NEW.employee_id, NEW.work_date
                        );
                        IF NOT verified_transition THEN
                            RAISE EXCEPTION 'verified compatible attendance supersession authorization is required' USING ERRCODE = '23514';
                        END IF;
                        IF parent.record_version = NEW.record_version THEN
                            IF parent.segment_kind <> NEW.segment_kind
                                OR parent.starts_at IS DISTINCT FROM NEW.starts_at
                                OR parent.ends_at IS DISTINCT FROM NEW.ends_at
                                OR parent.minutes <> NEW.minutes
                                OR parent.rate_minutes::jsonb <> NEW.rate_minutes::jsonb THEN
                                RAISE EXCEPTION 'compatible attendance identities must preserve the exact deficit' USING ERRCODE = '23514';
                            END IF;
                        ELSIF NOT (
                            parent.record_version = 1 AND NEW.record_version = 2
                            AND parent.segment_kind IN ('late_arrival', 'early_departure', 'full_day_absence')
                            AND NEW.segment_kind = 'daily_shortfall'
                            AND parent.minutes = NEW.minutes
                            AND parent.rate_minutes::jsonb = NEW.rate_minutes::jsonb
                        ) THEN
                            RAISE EXCEPTION 'unsupported compatible attendance predecessor transition' USING ERRCODE = '23514';
                        END IF;
                    END IF;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION enforce_overtime_decision_append_only() RETURNS trigger AS $$
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

            CREATE OR REPLACE FUNCTION enforce_attendance_exception_append_only() RETURNS trigger AS $$
            DECLARE parent attendance_exceptions%ROWTYPE;
            BEGIN
                IF TG_OP <> 'INSERT' THEN
                    RAISE EXCEPTION 'attendance exceptions are append-only' USING ERRCODE = '23514';
                END IF;

                IF NEW.record_version = 2 AND NEW.supersedes_id IS NOT NULL THEN
                    SELECT * INTO parent FROM attendance_exceptions WHERE id = NEW.supersedes_id FOR UPDATE;
                    IF NOT FOUND OR parent.record_version <> 2
                        OR parent.company_id <> NEW.company_id OR parent.pay_period_id <> NEW.pay_period_id
                        OR parent.employee_id <> NEW.employee_id OR parent.work_date <> NEW.work_date
                        OR parent.deficit_key <> NEW.deficit_key OR parent.fingerprint <> NEW.fingerprint
                        OR parent.segment_kind <> NEW.segment_kind OR parent.minutes <> NEW.minutes
                        OR parent.rate_minutes::jsonb <> NEW.rate_minutes::jsonb
                        OR (NEW.decision = 'revoked' AND parent.decision <> 'granted')
                        OR (NEW.decision IN ('granted', 'rejected') AND parent.decision <> 'revoked')
                        OR EXISTS (SELECT 1 FROM attendance_exceptions WHERE supersedes_id = parent.id) THEN
                        RAISE EXCEPTION 'decision must supersede the current matching V2 state' USING ERRCODE = '23514';
                    END IF;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            DROP FUNCTION IF EXISTS consume_verified_attendance_compatible_supersession(
                text, bigint, text, text, integer, text, text, bigint, bigint, bigint, date
            );
            SQL);
    }
};
