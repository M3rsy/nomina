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

        if ($mismatch = $this->firstMismatch()) {
            throw new RuntimeException("Cannot enforce vacation tenant invariants: {$mismatch->relation_name} has cross-company reference at id {$mismatch->sample_id}.");
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE vacations
                ADD CONSTRAINT vacations_company_id_id_unique UNIQUE (company_id, id),
                ADD CONSTRAINT vacations_company_id_id_employee_id_unique UNIQUE (company_id, id, employee_id),
                ADD CONSTRAINT vacations_company_employee_foreign FOREIGN KEY (company_id, employee_id) REFERENCES employees (company_id, id) ON DELETE CASCADE;
            ALTER TABLE employee_schedule_assignments
                ADD CONSTRAINT employee_schedule_assignments_company_id_id_unique UNIQUE (company_id, id);
            ALTER TABLE work_schedules
                ADD CONSTRAINT work_schedules_company_id_id_unique UNIQUE (company_id, id);
            ALTER TABLE work_schedule_profile_publications
                ADD CONSTRAINT work_schedule_publications_company_id_id_unique UNIQUE (company_id, id);
            ALTER TABLE vacation_days
                ADD CONSTRAINT vacation_days_company_id_id_unique UNIQUE (company_id, id),
                ADD CONSTRAINT vacation_days_company_id_id_employee_id_unique UNIQUE (company_id, id, employee_id),
                ADD CONSTRAINT vacation_days_company_id_id_vacation_id_employee_id_unique UNIQUE (company_id, id, vacation_id, employee_id),
                ADD CONSTRAINT vacation_days_company_vacation_employee_foreign FOREIGN KEY (company_id, vacation_id, employee_id) REFERENCES vacations (company_id, id, employee_id) ON DELETE CASCADE,
                ADD CONSTRAINT vacation_days_company_assignment_foreign FOREIGN KEY (company_id, employee_schedule_assignment_id) REFERENCES employee_schedule_assignments (company_id, id) ON DELETE SET NULL (employee_schedule_assignment_id),
                ADD CONSTRAINT vacation_days_company_schedule_foreign FOREIGN KEY (company_id, work_schedule_id) REFERENCES work_schedules (company_id, id) ON DELETE SET NULL (work_schedule_id),
                ADD CONSTRAINT vacation_days_company_publication_foreign FOREIGN KEY (company_id, work_schedule_profile_publication_id) REFERENCES work_schedule_profile_publications (company_id, id) ON DELETE SET NULL (work_schedule_profile_publication_id);
            ALTER TABLE vacation_balance_movements
                ADD CONSTRAINT vacation_balance_company_employee_foreign FOREIGN KEY (company_id, employee_id) REFERENCES employees (company_id, id) ON DELETE CASCADE,
                ADD CONSTRAINT vacation_balance_company_vacation_employee_foreign FOREIGN KEY (company_id, vacation_id, employee_id) REFERENCES vacations (company_id, id, employee_id) ON DELETE RESTRICT,
                ADD CONSTRAINT vacation_balance_company_day_employee_foreign FOREIGN KEY (company_id, vacation_day_id, employee_id) REFERENCES vacation_days (company_id, id, employee_id) ON DELETE RESTRICT,
                ADD CONSTRAINT vacation_balance_company_day_vacation_employee_foreign FOREIGN KEY (company_id, vacation_day_id, vacation_id, employee_id) REFERENCES vacation_days (company_id, id, vacation_id, employee_id) ON DELETE RESTRICT;
            ALTER TABLE payroll_results
                ADD CONSTRAINT payroll_results_company_vacation_employee_foreign FOREIGN KEY (company_id, vacation_id, employee_id) REFERENCES vacations (company_id, id, employee_id) ON DELETE RESTRICT,
                ADD CONSTRAINT payroll_results_company_day_employee_foreign FOREIGN KEY (company_id, vacation_day_id, employee_id) REFERENCES vacation_days (company_id, id, employee_id) ON DELETE RESTRICT,
                ADD CONSTRAINT payroll_results_company_day_vacation_employee_foreign FOREIGN KEY (company_id, vacation_day_id, vacation_id, employee_id) REFERENCES vacation_days (company_id, id, vacation_id, employee_id) ON DELETE RESTRICT;

            ALTER TABLE vacations DROP CONSTRAINT vacations_employee_id_foreign;
            ALTER TABLE vacation_days
                DROP CONSTRAINT vacation_days_vacation_id_foreign,
                DROP CONSTRAINT vacation_days_employee_id_foreign,
                DROP CONSTRAINT vacation_days_employee_schedule_assignment_id_foreign,
                DROP CONSTRAINT vacation_days_work_schedule_id_foreign,
                DROP CONSTRAINT vacation_days_work_schedule_profile_publication_id_foreign;
            ALTER TABLE vacation_balance_movements
                DROP CONSTRAINT vacation_balance_movements_employee_id_foreign,
                DROP CONSTRAINT vacation_balance_movements_vacation_id_foreign,
                DROP CONSTRAINT vacation_balance_movements_vacation_day_id_foreign;
            ALTER TABLE payroll_results
                DROP CONSTRAINT payroll_results_vacation_id_foreign,
                DROP CONSTRAINT payroll_results_vacation_day_id_foreign;
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE vacations
                ADD CONSTRAINT vacations_employee_id_foreign FOREIGN KEY (employee_id) REFERENCES employees (id) ON DELETE CASCADE;
            ALTER TABLE vacation_days
                ADD CONSTRAINT vacation_days_vacation_id_foreign FOREIGN KEY (vacation_id) REFERENCES vacations (id) ON DELETE CASCADE,
                ADD CONSTRAINT vacation_days_employee_id_foreign FOREIGN KEY (employee_id) REFERENCES employees (id) ON DELETE CASCADE,
                ADD CONSTRAINT vacation_days_employee_schedule_assignment_id_foreign FOREIGN KEY (employee_schedule_assignment_id) REFERENCES employee_schedule_assignments (id) ON DELETE SET NULL,
                ADD CONSTRAINT vacation_days_work_schedule_id_foreign FOREIGN KEY (work_schedule_id) REFERENCES work_schedules (id) ON DELETE SET NULL,
                ADD CONSTRAINT vacation_days_work_schedule_profile_publication_id_foreign FOREIGN KEY (work_schedule_profile_publication_id) REFERENCES work_schedule_profile_publications (id) ON DELETE SET NULL;
            ALTER TABLE vacation_balance_movements
                ADD CONSTRAINT vacation_balance_movements_employee_id_foreign FOREIGN KEY (employee_id) REFERENCES employees (id) ON DELETE CASCADE,
                ADD CONSTRAINT vacation_balance_movements_vacation_id_foreign FOREIGN KEY (vacation_id) REFERENCES vacations (id) ON DELETE RESTRICT,
                ADD CONSTRAINT vacation_balance_movements_vacation_day_id_foreign FOREIGN KEY (vacation_day_id) REFERENCES vacation_days (id) ON DELETE RESTRICT;
            ALTER TABLE payroll_results
                ADD CONSTRAINT payroll_results_vacation_id_foreign FOREIGN KEY (vacation_id) REFERENCES vacations (id) ON DELETE RESTRICT,
                ADD CONSTRAINT payroll_results_vacation_day_id_foreign FOREIGN KEY (vacation_day_id) REFERENCES vacation_days (id) ON DELETE RESTRICT;

            ALTER TABLE payroll_results
                DROP CONSTRAINT payroll_results_company_day_vacation_employee_foreign,
                DROP CONSTRAINT payroll_results_company_day_employee_foreign,
                DROP CONSTRAINT payroll_results_company_vacation_employee_foreign;
            ALTER TABLE vacation_balance_movements
                DROP CONSTRAINT vacation_balance_company_day_vacation_employee_foreign,
                DROP CONSTRAINT vacation_balance_company_day_employee_foreign,
                DROP CONSTRAINT vacation_balance_company_vacation_employee_foreign,
                DROP CONSTRAINT vacation_balance_company_employee_foreign;
            ALTER TABLE vacation_days
                DROP CONSTRAINT vacation_days_company_publication_foreign,
                DROP CONSTRAINT vacation_days_company_schedule_foreign,
                DROP CONSTRAINT vacation_days_company_assignment_foreign,
                DROP CONSTRAINT vacation_days_company_vacation_employee_foreign,
                DROP CONSTRAINT vacation_days_company_id_id_vacation_id_employee_id_unique,
                DROP CONSTRAINT vacation_days_company_id_id_employee_id_unique,
                DROP CONSTRAINT vacation_days_company_id_id_unique;
            ALTER TABLE work_schedule_profile_publications
                DROP CONSTRAINT work_schedule_publications_company_id_id_unique;
            ALTER TABLE work_schedules
                DROP CONSTRAINT work_schedules_company_id_id_unique;
            ALTER TABLE employee_schedule_assignments
                DROP CONSTRAINT employee_schedule_assignments_company_id_id_unique;
            ALTER TABLE vacations
                DROP CONSTRAINT vacations_company_employee_foreign,
                DROP CONSTRAINT vacations_company_id_id_employee_id_unique,
                DROP CONSTRAINT vacations_company_id_id_unique;
            SQL);
    }

    private function firstMismatch(): ?object
    {
        return DB::selectOne(<<<'SQL'
            select relation_name, sample_id from (
                select 'vacations.employee_id' relation_name, v.id sample_id from vacations v join employees e on e.id = v.employee_id where e.company_id <> v.company_id
                union all select 'vacation_days.vacation_id', vd.id from vacation_days vd join vacations v on v.id = vd.vacation_id where (v.company_id, v.employee_id) <> (vd.company_id, vd.employee_id)
                union all select 'vacation_days.employee_id', vd.id from vacation_days vd join employees e on e.id = vd.employee_id where e.company_id <> vd.company_id
                union all select 'vacation_days.employee_schedule_assignment_id', vd.id from vacation_days vd join employee_schedule_assignments a on a.id = vd.employee_schedule_assignment_id where a.company_id <> vd.company_id
                union all select 'vacation_days.work_schedule_id', vd.id from vacation_days vd join work_schedules s on s.id = vd.work_schedule_id where s.company_id <> vd.company_id
                union all select 'vacation_days.work_schedule_profile_publication_id', vd.id from vacation_days vd join work_schedule_profile_publications p on p.id = vd.work_schedule_profile_publication_id where p.company_id <> vd.company_id
                union all select 'vacation_balance_movements.employee_id', m.id from vacation_balance_movements m join employees e on e.id = m.employee_id where e.company_id <> m.company_id
                union all select 'vacation_balance_movements.vacation_id', m.id from vacation_balance_movements m join vacations v on v.id = m.vacation_id where (v.company_id, v.employee_id) <> (m.company_id, m.employee_id)
                union all select 'vacation_balance_movements.vacation_day_id', m.id from vacation_balance_movements m join vacation_days vd on vd.id = m.vacation_day_id where (vd.company_id, vd.employee_id) <> (m.company_id, m.employee_id)
                union all select 'vacation_balance_movements.vacation_id+vacation_day_id', m.id from vacation_balance_movements m join vacation_days vd on vd.id = m.vacation_day_id where m.vacation_id is not null and vd.vacation_id <> m.vacation_id
                union all select 'payroll_results.vacation_id', pr.id from payroll_results pr join vacations v on v.id = pr.vacation_id where (v.company_id, v.employee_id) <> (pr.company_id, pr.employee_id)
                union all select 'payroll_results.vacation_day_id', pr.id from payroll_results pr join vacation_days vd on vd.id = pr.vacation_day_id where (vd.company_id, vd.employee_id) <> (pr.company_id, pr.employee_id)
                union all select 'payroll_results.vacation_id+vacation_day_id', pr.id from payroll_results pr join vacation_days vd on vd.id = pr.vacation_day_id where pr.vacation_id is not null and vd.vacation_id <> pr.vacation_id
            ) mismatches order by relation_name, sample_id limit 1
            SQL);
    }
};
