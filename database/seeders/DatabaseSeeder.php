<?php

namespace Database\Seeders;

use App\Models\Company;
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
    }
}
