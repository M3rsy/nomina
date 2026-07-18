<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // In production only the minimal, idempotent ProductionSeeder runs.
        // Demo data must never be installed on a live tenant.
        if (app()->environment('production')) {
            $this->call(ProductionSeeder::class);

            return;
        }

        $this->call(PermissionRoleSeeder::class);

        $companyA = Company::firstOrCreate(
            ['slug' => 'empresa-a'],
            ['name' => 'Empresa A', 'slug' => 'empresa-a', 'legal_id' => 'RTN-A-001', 'is_active' => true]
        );

        $companyB = Company::firstOrCreate(
            ['slug' => 'empresa-b'],
            ['name' => 'Empresa B', 'slug' => 'empresa-b', 'legal_id' => 'RTN-B-001', 'is_active' => true]
        );

        $superAdmin = User::firstOrCreate(
            ['email' => 'admin@nomina.test'],
            [
                'name' => 'Super Administrador',
                'email' => 'admin@nomina.test',
                'password' => Hash::make('password'),
                'company_id' => null,
                'is_active' => true,
            ]
        );
        $superAdmin->syncRoles('super_admin');

        $adminA = User::firstOrCreate(
            ['email' => 'admin_a@empresa-a.test'],
            [
                'name' => 'Administrador Empresa A',
                'email' => 'admin_a@empresa-a.test',
                'password' => Hash::make('password'),
                'company_id' => $companyA->id,
                'is_active' => true,
            ]
        );
        $adminA->syncRoles('company_admin');

        $adminB = User::firstOrCreate(
            ['email' => 'admin_b@empresa-b.test'],
            [
                'name' => 'Administrador Empresa B',
                'email' => 'admin_b@empresa-b.test',
                'password' => Hash::make('password'),
                'company_id' => $companyB->id,
                'is_active' => true,
            ]
        );
        $adminB->syncRoles('company_admin');

        Employee::withoutRevisions(function () use ($companyA, $companyB): void {
            $this->seedEmployee($companyA, '13767', 'NORA', 'FLORES');
            $this->seedEmployee($companyA, '1222', 'EMPLEADO', '1222');
            $this->seedEmployee($companyA, '12884', 'EDGAR', 'ENCARNACION');
            $this->seedEmployee($companyA, '44', 'GERSON L', 'REYES');

            $this->seedEmployee($companyB, '6419', 'OLVIN', 'CARCAMO');
            $this->seedEmployee($companyB, '9069', 'EMPLEADO', '9069');
        });

        $this->call([
            PayPeriodSeeder::class,
            WorkScheduleSeeder::class,
            HolidaysSeeder::class,
            AuditSeeder::class,
        ]);
    }

    private function seedEmployee(Company $company, string $externalId, string $firstName, string $lastName): Employee
    {
        return Employee::firstOrCreate(
            ['company_id' => $company->id, 'external_id' => $externalId],
            [
                'company_id' => $company->id,
                'external_id' => $externalId,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'dni' => fake()->numerify('#############'),
                'sex' => fake()->randomElement(['M', 'F']),
                'birth_date' => fake()->date(),
                'address' => fake()->streetAddress(),
                'phone' => fake()->numerify('########'),
                'job_title' => fake()->randomElement([
                    'Guardia de seguridad',
                    'Administrador',
                    'Supervisor',
                    'Operador',
                ]),
                'expected_salary' => fake()->randomFloat(2, 8000, 30000),
                'is_active' => true,
                'hired_at' => fake()->date(),
                'notes' => null,
                'metadata' => null,
            ]
        );
    }
}
