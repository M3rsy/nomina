<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeRevision;
use App\Models\LoginAttempt;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class AuditSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->seedLoginAttempts();
        $this->seedEmployeeRevisions();
    }

    private function seedLoginAttempts(): void
    {
        $admin = User::where('email', 'admin@nomina.test')->first();
        $adminA = User::where('email', 'admin_a@empresa-a.test')->first();

        if ($admin) {
            LoginAttempt::firstOrCreate(
                [
                    'email' => $admin->email,
                    'ip' => '127.0.0.1',
                    'success' => true,
                    'user_agent' => 'seeder',
                ],
                [
                    'user_id' => $admin->id,
                    'company_id' => $admin->company_id,
                ]
            );
        }

        if ($adminA) {
            LoginAttempt::firstOrCreate(
                [
                    'email' => $adminA->email,
                    'ip' => '127.0.0.1',
                    'success' => true,
                    'user_agent' => 'seeder',
                ],
                [
                    'user_id' => $adminA->id,
                    'company_id' => $adminA->company_id,
                ]
            );
        }
    }

    private function seedEmployeeRevisions(): void
    {
        $company = Company::where('slug', 'empresa-a')->first();

        if (! $company) {
            return;
        }

        $employee = Employee::withoutCompanyScope()
            ->where('company_id', $company->id)
            ->first();

        if (! $employee) {
            return;
        }

        $admin = User::where('email', 'admin_a@empresa-a.test')->first();

        EmployeeRevision::firstOrCreate(
            [
                'employee_id' => $employee->id,
                'field' => 'job_title',
                'old_value' => $employee->job_title,
                'new_value' => $employee->job_title,
            ],
            [
                'user_id' => $admin?->id,
                'reason' => 'Demo seed',
            ]
        );
    }
}
