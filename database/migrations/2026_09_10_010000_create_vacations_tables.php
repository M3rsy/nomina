<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vacations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('start_date');
            $table->date('end_date');
            $table->string('status', 16)->default('approved');
            $table->text('notes')->nullable();
            $table->json('excluded_dates')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at');
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'employee_id', 'start_date', 'end_date'], 'vacations_employee_range_idx');
            $table->index(['company_id', 'status', 'start_date'], 'vacations_status_start_idx');
        });

        Schema::create('vacation_days', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vacation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('work_date');
            $table->dateTime('scheduled_start');
            $table->dateTime('scheduled_end');
            $table->unsignedInteger('planned_minutes');
            $table->json('rate_minutes');
            $table->char('snapshot_fingerprint', 64);
            $table->unsignedInteger('holiday_generation')->default(0);
            $table->foreignId('employee_schedule_assignment_id')->nullable()->constrained('employee_schedule_assignments')->nullOnDelete();
            $table->foreignId('work_schedule_id')->nullable()->constrained('work_schedules')->nullOnDelete();
            $table->foreignId('work_schedule_profile_publication_id')->nullable()->constrained('work_schedule_profile_publications')->nullOnDelete();
            // NULL represents an inactive historical row. Multiple NULLs are allowed,
            // while TRUE makes the employee/date uniqueness constraint apply to active rows.
            $table->boolean('active_marker')->nullable()->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'employee_id', 'work_date', 'active_marker'], 'vacation_days_active_employee_date_unique');
            $table->index(['company_id', 'employee_id', 'work_date'], 'vacation_days_employee_date_idx');
            $table->index(['vacation_id', 'work_date'], 'vacation_days_vacation_date_idx');
        });

        Schema::create('vacation_balance_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vacation_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('vacation_day_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('type', 32);
            $table->integer('days');
            $table->text('reason');
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['company_id', 'employee_id', 'created_at'], 'vacation_balance_employee_idx');
            $table->unique(['vacation_day_id', 'type'], 'vacation_balance_day_type_unique');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                ALTER TABLE vacations
                    ADD CONSTRAINT vacations_dates_check CHECK (end_date >= start_date),
                    ADD CONSTRAINT vacations_status_check CHECK (status IN ('approved', 'cancelled')),
                    ADD CONSTRAINT vacations_cancellation_check CHECK (
                        (status = 'approved' AND cancelled_at IS NULL AND cancelled_by IS NULL AND cancellation_reason IS NULL)
                        OR (status = 'cancelled' AND cancelled_at IS NOT NULL AND btrim(cancellation_reason) <> '')
                    );
                ALTER TABLE vacation_days
                    ADD CONSTRAINT vacation_days_values_check CHECK (
                        scheduled_end > scheduled_start AND planned_minutes > 0
                        AND snapshot_fingerprint ~ '^[a-f0-9]{64}$'
                        AND (active_marker IS NULL OR active_marker = TRUE)
                    );
                ALTER TABLE vacation_balance_movements
                    ADD CONSTRAINT vacation_balance_values_check CHECK (
                        type IN ('manual_adjustment', 'vacation_consumption', 'vacation_reversal')
                        AND days <> 0 AND btrim(reason) <> ''
                    );
                DROP FUNCTION IF EXISTS reject_vacation_balance_movement_mutation() CASCADE;
                CREATE FUNCTION reject_vacation_balance_movement_mutation() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'vacation balance movements are append-only' USING ERRCODE = '23514';
                END;
                $$ LANGUAGE plpgsql;
                CREATE TRIGGER vacation_balance_movements_immutable
                    BEFORE UPDATE OR DELETE ON vacation_balance_movements
                    FOR EACH ROW EXECUTE FUNCTION reject_vacation_balance_movement_mutation();
                SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS reject_vacation_balance_movement_mutation() CASCADE');
        }

        Schema::dropIfExists('vacation_balance_movements');
        Schema::dropIfExists('vacation_days');
        Schema::dropIfExists('vacations');
    }
};
