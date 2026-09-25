# Stitch Users Module

## Goal

Integrate the Stitch-inspired user management visuals into the real Laravel/Livewire users module without changing backend behavior.

## Scope

- `/usuarios`
- `/usuarios/crear`
- `/usuarios/{user}/editar`

## Constraints

- Do not add React, Vue, Next.js, CDN Tailwind, Google Fonts, Material Symbols runtime, or inline scripts.
- Do not change backend logic, models, services, policies, routes, authorization, tenant scoping, validation, password hashing, or session revocation behavior.
- Do not invent unsupported Stitch behavior: invitations, MFA/2FA, SSO, auditor roles, payroll specialist roles, branch/site assignment, SOX workflow, fake last-login timestamps, or audit links.
- Preserve Livewire bindings and actions: search, save, role/company/password fields, edit activation recovery behavior, and loading targets.

## Tasks

- [x] Add RED presentation coverage for users index/create/edit.
- [x] Migrate `resources/views/livewire/usuarios/index.blade.php` using real user/company/role/status data.
- [x] Migrate `resources/views/livewire/usuarios/create.blade.php` preserving create fields and save contract.
- [x] Migrate `resources/views/livewire/usuarios/edit.blade.php` preserving edit fields, recovery/activation controls, and save contract.
- [x] Run focused users/auth/UI tests.
- [x] Verify preview combined branch and record review evidence.

## Evidence

- Branch: `preview/stitch-ui-combined`
- Scope: users index/create/edit only.
- RED: `php artisan test tests/Feature/Usuarios/UserManagementPresentationTest.php` failed because the prior views did not render `data-users-index="workspace"`, `data-users-form="create"`, or `data-users-form="edit"`.
- GREEN focused verification: `php artisan test tests/Feature/Usuarios/UserManagementPresentationTest.php tests/Feature/MultiTenant/UserPolicyTest.php tests/Feature/MultiTenant/CompanyIsolationTest.php tests/Feature/Auth/CompanyAdminCompanyTest.php tests/Feature/Ui/AdministrationLoadingFeedbackTest.php tests/Feature/Ui/DesignSystemComponentsTest.php` passed with 35 tests and 354 assertions.
- Guardrails verified: no CDN Tailwind, Material Symbols runtime, Google Fonts, inline scripts, invitations, MFA/2FA, SSO, payroll specialist, auditor role, SOX, fake last login, fake audit actions, drafts, or branch/site assignment in `resources/views/livewire/usuarios`.
- Preserved contracts: `wire:model.live="search"`, `wire:submit="save"`, create/edit `name`, `email`, `password`, `role`, `company_id`, edit `is_active`, and shared loading button target `save`.

- Preview combined verification: `php artisan test tests/Feature/Usuarios/UserManagementPresentationTest.php tests/Feature/MultiTenant/UserPolicyTest.php tests/Feature/MultiTenant/CompanyIsolationTest.php tests/Feature/Auth/CompanyAdminCompanyTest.php tests/Feature/CompanyIndexPresentationTest.php tests/Feature/Empresas/CompanyCreationTest.php tests/Feature/Fase3/JornadasFeriadosTest.php tests/Feature/Archivos/IndexTest.php tests/Feature/FileIsolationTest.php tests/Feature/Empleados/IndexTest.php tests/Feature/Empleados/EmployeeCrudTest.php tests/Feature/Nomina/IndexTest.php tests/Feature/Ui/AdministrationLoadingFeedbackTest.php tests/Feature/Ui/OperationsLoadingFeedbackTest.php tests/Feature/Ui/DesignSystemComponentsTest.php` passed with 133 tests and 915 assertions.
- Build/cache verification: `npm run build`, `php artisan optimize:clear`, and `php artisan view:clear` passed.

- Native review approved and acknowledged for the initial UI-only candidate: `review-f3684585e43ab0b3`.
- Scope expanded after user request to add real direct actions: deactivate and delete.
- RED actions: `php artisan test tests/Feature/Usuarios/UserManagementActionsTest.php` failed because `deactivate`/`delete` methods and row buttons did not exist.
- GREEN actions: `php artisan test tests/Feature/Usuarios/UserManagementActionsTest.php` passed with 4 tests and 17 assertions.
- Focused verification after actions: `php artisan test tests/Feature/Usuarios/UserManagementPresentationTest.php tests/Feature/Usuarios/UserManagementActionsTest.php tests/Feature/MultiTenant/UserPolicyTest.php tests/Feature/MultiTenant/CompanyIsolationTest.php tests/Feature/Auth/CompanyAdminCompanyTest.php tests/Feature/Ui/AdministrationLoadingFeedbackTest.php tests/Feature/Ui/DesignSystemComponentsTest.php` passed with 39 tests and 371 assertions.
- Direct deactivate uses the existing `update` policy, blocks self-deactivation, sets `is_active=false`, and revokes active sessions.
- Direct delete uses the existing `delete` policy, blocks self-deletion, soft-deletes the user, and revokes active sessions.

- Preview combined verification after action expansion: `php artisan test tests/Feature/Usuarios/UserManagementPresentationTest.php tests/Feature/Usuarios/UserManagementActionsTest.php tests/Feature/MultiTenant/UserPolicyTest.php tests/Feature/MultiTenant/CompanyIsolationTest.php tests/Feature/Auth/CompanyAdminCompanyTest.php tests/Feature/CompanyIndexPresentationTest.php tests/Feature/Empresas/CompanyCreationTest.php tests/Feature/Fase3/JornadasFeriadosTest.php tests/Feature/Archivos/IndexTest.php tests/Feature/FileIsolationTest.php tests/Feature/Empleados/IndexTest.php tests/Feature/Empleados/EmployeeCrudTest.php tests/Feature/Nomina/IndexTest.php tests/Feature/Ui/AdministrationLoadingFeedbackTest.php tests/Feature/Ui/OperationsLoadingFeedbackTest.php tests/Feature/Ui/DesignSystemComponentsTest.php` passed with 137 tests and 932 assertions.
- Build/cache verification after action expansion: `npm run build`, `php artisan optimize:clear`, and `php artisan view:clear` passed.
- Guardrails reverified: no CDN Tailwind, Material Symbols runtime, Google Fonts, inline scripts, invitations, MFA/2FA, SSO, unsupported roles, SOX, fake last login, fake audit actions, drafts, or branch/site assignment in `resources/views/livewire/usuarios`.

## Pending checks

- Native review approved and acknowledged for the expanded candidate: `review-861aad2be91755ea`.
- Advisory informational, non-blocking: `R3-delete-transaction`.

## Pending checks

- Browser review remains pending for responsive density on the users table and sticky action dock.
- Publishing PR slice is pending.
