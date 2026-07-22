<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\User;
use App\Services\Attendance\DefaultWorkScheduleProvisioner;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class ProductionSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(DefaultWorkScheduleProvisioner $scheduleProvisioner): void
    {
        // Create the canonical permission and role set first.
        $this->call(PermissionRoleSeeder::class);

        // Ensure at least one company exists for the tenant boundary.
        $company = Company::firstOrCreate(
            ['slug' => 'empresa-principal'],
            [
                'name' => 'Empresa Principal',
                'slug' => 'empresa-principal',
                'legal_id' => '00000000000000',
                'is_active' => true,
            ]
        );

        $scheduleProvisioner->provision($company);

        // Create the initial super admin from environment values.
        $email = env('SUPER_ADMIN_EMAIL', 'admin@nomina.test');
        $password = env('SUPER_ADMIN_PASSWORD');

        if (empty($password)) {
            $this->command->warn('SUPER_ADMIN_PASSWORD is empty; the super admin account will not be able to authenticate until a password is set.');
        }

        $superAdmin = User::firstOrCreate(
            ['email' => $email],
            [
                'name' => 'Super Administrador',
                'email' => $email,
                'password' => Hash::make($password ?? 'changeme'),
                'company_id' => null,
                'is_active' => true,
            ]
        );

        $superAdmin->syncRoles('super_admin');

        $this->command->info('ProductionSeeder completed: roles, permissions, first company and super admin are ready.');
    }
}
