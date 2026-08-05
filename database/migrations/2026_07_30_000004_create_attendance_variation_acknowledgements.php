<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_variation_acknowledgements', function (Blueprint $table): void {
            $table->id();
            $table->unsignedSmallInteger('record_version')->default(2);
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('pay_period_id')->constrained()->restrictOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->date('work_date');
            $table->char('variation_key', 64);
            $table->char('fingerprint', 64);
            $table->string('variation_kind', 32);
            $table->dateTime('entry_at');
            $table->text('reason');
            $table->foreignId('acknowledged_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(
                ['company_id', 'pay_period_id', 'employee_id', 'work_date', 'variation_key', 'fingerprint'],
                'attendance_variation_ack_identity_unique',
            );
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                ALTER TABLE attendance_variation_acknowledgements
                    ADD CONSTRAINT attendance_variation_ack_values_check CHECK (
                        record_version = 2 AND variation_kind = 'schedule_entry'
                        AND variation_key ~ '^[a-f0-9]{64}$' AND fingerprint ~ '^[a-f0-9]{64}$'
                        AND btrim(reason) <> ''
                    );
                DROP FUNCTION IF EXISTS reject_attendance_variation_ack_mutation() CASCADE;
                CREATE FUNCTION reject_attendance_variation_ack_mutation() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'attendance variation acknowledgements are append-only' USING ERRCODE = '23514';
                END;
                $$ LANGUAGE plpgsql;
                CREATE TRIGGER attendance_variation_acknowledgements_immutable
                    BEFORE UPDATE OR DELETE ON attendance_variation_acknowledgements
                    FOR EACH ROW EXECUTE FUNCTION reject_attendance_variation_ack_mutation();
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_variation_acknowledgements');
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS reject_attendance_variation_ack_mutation()');
        }
    }
};
