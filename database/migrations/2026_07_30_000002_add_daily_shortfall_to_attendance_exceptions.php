<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_exceptions', function (Blueprint $table): void {
            $table->unsignedSmallInteger('record_version')->default(1)->after('id');
            $table->dateTime('starts_at')->nullable()->change();
            $table->dateTime('ends_at')->nullable()->change();
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE attendance_exceptions
                ADD CONSTRAINT attendance_exceptions_v2_values_check CHECK (
                    record_version = 1 OR (
                        record_version = 2
                        AND segment_kind = 'daily_shortfall'
                        AND starts_at IS NULL AND ends_at IS NULL
                        AND minutes BETWEEN 1 AND 480
                        AND decision IN ('granted', 'rejected', 'revoked')
                        AND deficit_key ~ '^[a-f0-9]{64}$' AND fingerprint ~ '^[a-f0-9]{64}$'
                        AND decided_by IS NOT NULL AND btrim(reason) <> ''
                        AND rate_minutes::jsonb = jsonb_build_object(
                            'ordinary', minutes,
                            'extra25', 0,
                            'extra50', 0,
                            'extra75', 0,
                            'extra100', 0
                        )
                        AND (decision IN ('granted', 'rejected')
                            OR (decision = 'revoked' AND supersedes_id IS NOT NULL))
                    )
                );

            DROP FUNCTION IF EXISTS enforce_attendance_exception_append_only() CASCADE;
            CREATE FUNCTION enforce_attendance_exception_append_only() RETURNS trigger AS $$
            DECLARE
                parent attendance_exceptions%ROWTYPE;
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

            CREATE TRIGGER attendance_exceptions_append_only
                BEFORE INSERT OR UPDATE OR DELETE ON attendance_exceptions
                FOR EACH ROW EXECUTE FUNCTION enforce_attendance_exception_append_only();
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS attendance_exceptions_append_only ON attendance_exceptions');
            DB::unprepared('DROP FUNCTION IF EXISTS enforce_attendance_exception_append_only()');
            DB::unprepared('ALTER TABLE attendance_exceptions DROP CONSTRAINT IF EXISTS attendance_exceptions_v2_values_check');
        }
    }
};
