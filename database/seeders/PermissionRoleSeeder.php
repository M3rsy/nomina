<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class PermissionRoleSeeder extends Seeder
{
    use WithoutModelEvents;

    public const PERMISSIONS = [
        'companies.view',
        'companies.create',
        'companies.update',
        'companies.activate',
        'companies.delete',
        'users.view',
        'users.create',
        'users.update',
        'users.delete',
        'employees.view',
        'employees.create',
        'employees.update',
        'employees.delete',
        'employees.activate',
        'payroll_periods.create',
        'payroll_periods.view',
        'payroll_periods.update',
        'payroll_periods.delete',
        'files.upload',
        'files.view',
        'files.delete',
        'marks.view',
        'marks.update',
        'marks.delete',
        'marks.justify',
        'payroll.view',
        'payroll.process',
        'payroll.approve',
        'payroll.export',
        'payroll.receipts.download',
        'audit.view',
        'backups.create',
        'backups.restore',
    ];

    public const ROLES = [
        'super_admin' => self::PERMISSIONS,
        'company_admin' => [
            'employees.view',
            'employees.create',
            'employees.update',
            'employees.delete',
            'employees.activate',
            'users.view',
            'users.create',
            'users.update',
            'payroll_periods.create',
            'payroll_periods.view',
            'payroll_periods.update',
            'payroll_periods.delete',
            'files.upload',
            'files.view',
            'files.delete',
            'marks.view',
            'marks.update',
            'marks.justify',
            'payroll.view',
            'payroll.process',
            'payroll.approve',
            'payroll.export',
            'payroll.receipts.download',
            'audit.view',
            'backups.create',
        ],
    ];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $permissions = [];
        foreach (self::PERMISSIONS as $name) {
            $permissions[$name] = Permission::firstOrCreate(
                ['name' => $name, 'guard_name' => 'web'],
                ['name' => $name, 'guard_name' => 'web']
            );
        }

        foreach (self::ROLES as $roleName => $permissionNames) {
            $role = Role::firstOrCreate(
                ['name' => $roleName, 'guard_name' => 'web'],
                ['name' => $roleName, 'guard_name' => 'web']
            );

            $role->syncPermissions($permissionNames);
        }
    }
}
