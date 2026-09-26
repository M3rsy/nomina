# Stitch Holidays Index

## Goal

Integrate the Stitch-inspired holidays/calendar labor UI into the real Livewire holidays module without changing payroll, attendance, calendar-generation, or authorization logic.

## Scope

- `/feriados` index only, including its existing create/edit/delete modal flows.

## Constraints

- Do not add React, Vue, Next.js, CDN Tailwind, Google Fonts, Material Symbols runtime, or inline scripts.
- Do not change models, services, policies, routes, payroll rules, attendance analysis, holiday calendar generation, or authorization.
- Do not invent unsupported Stitch behavior: Diario Oficial sync, national decree import, export CSV/PDF, legal manual, biometric automation, fixed 200% rates, scope/year/status filters, month view, official/sectorial categories, or fake processed counts.
- Preserve Livewire contracts: `wire:model.live="search"`, `openCreateModal`, `toggle(...)`, `edit(...)`, `confirmDelete(...)`, `save`, `delete`, company-required toast, and loading targets.

## Tasks

- [x] Add RED presentation coverage for the Stitch-inspired holidays workspace.
- [x] Migrate `resources/views/livewire/feriados/index.blade.php` using real holiday/company data and existing actions.
- [x] Run focused Feriados/UI tests.
- [x] Verify preview combined branch and record review evidence.
- [x] Add RED modal coverage for the existing create/edit dialog.
- [x] Migrate the create/edit modal to the Stitch-inspired dialog while preserving real fields only.

## Evidence

- Branch: `preview/stitch-ui-combined`
- Scope: `/feriados` index and existing modal flows only.
- RED: `php artisan test tests/Feature/Feriados/HolidayPresentationTest.php` failed because the prior view did not render `data-holidays-index="workspace"`.
- GREEN focused verification: `php artisan test tests/Feature/Feriados/HolidayPresentationTest.php tests/Feature/Feriados/HolidayTest.php tests/Feature/Fase3/JornadasFeriadosTest.php tests/Feature/Ui/OperationsLoadingFeedbackTest.php tests/Feature/Ui/DesignSystemComponentsTest.php` passed with 35 tests and 214 assertions.
- Guardrails verified: no CDN Tailwind, Material Symbols runtime, Google Fonts, inline scripts, Diario Oficial sync, national calendar import, fake 200% rates, biometric automation, export, legal manual, fake decree counts, fake processed counts, or unsupported filters in `resources/views/livewire/feriados/index.blade.php`.
- Preserved contracts: `wire:model.live="search"`, `openCreateModal`, `toggle(...)`, `edit(...)`, `confirmDelete(...)`, `save`, `delete`, company-required toast, modal flow, and scoped loading targets.

- Preview combined verification: `php artisan test tests/Feature/Feriados/HolidayPresentationTest.php tests/Feature/Feriados/HolidayTest.php tests/Feature/Fase3/JornadasFeriadosTest.php tests/Feature/Usuarios/UserManagementPresentationTest.php tests/Feature/Usuarios/UserManagementActionsTest.php tests/Feature/MultiTenant/UserPolicyTest.php tests/Feature/MultiTenant/CompanyIsolationTest.php tests/Feature/Auth/CompanyAdminCompanyTest.php tests/Feature/CompanyIndexPresentationTest.php tests/Feature/Empresas/CompanyCreationTest.php tests/Feature/Archivos/IndexTest.php tests/Feature/FileIsolationTest.php tests/Feature/Empleados/IndexTest.php tests/Feature/Empleados/EmployeeCrudTest.php tests/Feature/Nomina/IndexTest.php tests/Feature/Ui/AdministrationLoadingFeedbackTest.php tests/Feature/Ui/OperationsLoadingFeedbackTest.php tests/Feature/Ui/DesignSystemComponentsTest.php` passed with 143 tests and 964 assertions.
- Build/cache verification: `npm run build`, `php artisan optimize:clear`, and `php artisan view:clear` passed.

- Native review approved and acknowledged before modal refinement: `review-5f3fa6a7dda69ad5`.
- Non-blocking advisories from that review: `R3-disabled-guidance`, `R3-time-dependent-test`.
- Modal RED: `php artisan test tests/Feature/Feriados/HolidayPresentationTest.php` failed because the previous modal lacked dialog semantics (`role="dialog"`).
- Modal GREEN focused verification: `php artisan test tests/Feature/Feriados/HolidayPresentationTest.php tests/Feature/Feriados/HolidayTest.php tests/Feature/Fase3/JornadasFeriadosTest.php tests/Feature/Ui/OperationsLoadingFeedbackTest.php tests/Feature/Ui/DesignSystemComponentsTest.php` passed with 36 tests and 236 assertions.
- Modal guardrails verified: no CDN Tailwind, Google Fonts, Material Symbols runtime, inline scripts, recurring annual behavior, legal scope/classification, Código de Trabajo claims, fake 200% recargo, or biometric sync were added.

- Preview combined verification after modal refinement: `php artisan test tests/Feature/Feriados/HolidayPresentationTest.php tests/Feature/Feriados/HolidayTest.php tests/Feature/Fase3/JornadasFeriadosTest.php tests/Feature/Usuarios/UserManagementPresentationTest.php tests/Feature/Usuarios/UserManagementActionsTest.php tests/Feature/MultiTenant/UserPolicyTest.php tests/Feature/MultiTenant/CompanyIsolationTest.php tests/Feature/Auth/CompanyAdminCompanyTest.php tests/Feature/CompanyIndexPresentationTest.php tests/Feature/Empresas/CompanyCreationTest.php tests/Feature/Archivos/IndexTest.php tests/Feature/FileIsolationTest.php tests/Feature/Empleados/IndexTest.php tests/Feature/Empleados/EmployeeCrudTest.php tests/Feature/Nomina/IndexTest.php tests/Feature/Ui/AdministrationLoadingFeedbackTest.php tests/Feature/Ui/OperationsLoadingFeedbackTest.php tests/Feature/Ui/DesignSystemComponentsTest.php` passed with 144 tests and 986 assertions.
- Build/cache verification after modal refinement: `npm run build`, `php artisan optimize:clear`, and `php artisan view:clear` passed.

- Native review after modal refinement approved and acknowledged: `review-795efd9b82ceed07`.
- Non-blocking advisories repeated: `R3-disabled-guidance`, `R3-time-dependent-test`.

## Pending checks

- Browser review remains pending for responsive table density and modal layout.
- Publishing PR slice is pending explicit user approval.
