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
        'pay_periods.view',
        'pay_periods.manage',
        'files.upload',
        'files.view',
        'files.manage',
        'files.delete',
        'marks.view',
        'marks.manage',
        'marks.edit',
        'payroll.view',
        'payroll.process',
        'payroll.approve',
        'payroll.export',
        'payroll.receipts.download',
        'audit.view',
        'backups.run',
        'backups.restore',
        'work_schedules.view',
        'work_schedules.manage',
        'holidays.view',
        'holidays.manage',
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
            'pay_periods.view',
            'pay_periods.manage',
            'files.upload',
            'files.view',
            'files.manage',
            'files.delete',
            'marks.view',
            'marks.manage',
            'marks.edit',
            'payroll.view',
            'payroll.process',
            'payroll.approve',
            'payroll.export',
            'payroll.receipts.download',
            'audit.view',
            'work_schedules.view',
            'work_schedules.manage',
            'holidays.view',
            'holidays.manage',
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

            $unmanagedPermissionNames = $role->permissions
                ->pluck('name')
                ->diff(self::PERMISSIONS)
                ->all();

            $role->syncPermissions([...$permissionNames, ...$unmanagedPermissionNames]);
        }
    }
}
