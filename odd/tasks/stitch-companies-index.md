# Stitch Companies Index

## Goal

Migrate only the companies index (`/empresas`) toward the Google Stitch Empresas design while preserving Livewire behavior, authorization, search, pagination, activation toggle, and delete behavior.

## Non-goals

- Do not modify backend logic, Livewire PHP classes, models, policies, routes, tenancy, session company switching, or company creation/edit behavior.
- Do not introduce React, Vue, Next.js, CDN Tailwind, Material Symbols, Google Fonts, inline scripts, or another frontend framework.
- Do not copy Stitch HTML indiscriminately.
- Do not invent fake fiscal reports, payroll mass, employee counts, regimes, SAR verification, cities, branches, or export actions.

## Constraints

- Preserve `wire:model.live="search"`.
- Preserve create navigation to `/empresas/crear` under existing authorization.
- Preserve edit navigation to `/empresas/{id}/editar` under existing authorization.
- Preserve `wire:click="toggle(...)"`, `wire:click="delete(...)"`, loading targets, and delete confirmation.
- Preserve the named keyboard scroll table region (`role="region"`, `aria-labelledby="companies-heading"`, `tabindex="0"`, `overflow-x-auto`).
- Use real visible data only: paginator total/current count, visible active/inactive counts, active company context, company name/slug/legal id/status.
- Use TDD: behavior test first, then Blade changes.

## Tasks

- [x] Add/adjust Feature coverage for the Stitch-inspired Empresas structure.
- [x] Migrate `resources/views/livewire/empresas/index.blade.php` using existing UI components and semantic tokens.
- [x] Run focused Empresas/UI tests.
- [x] Record verification evidence and browser-review risks.

## Evidence

- Branch: `preview/stitch-ui-combined`
- Scope: `/empresas` index only.
- RED: `php artisan test tests/Feature/CompanyIndexPresentationTest.php --filter='companies page presents'` failed because the prior view did not render `data-companies-index="workspace"`.
- GREEN: the same focused test passed with 1 test and 15 assertions after the Blade migration.
- Focused verification: `php artisan test tests/Feature/CompanyIndexPresentationTest.php tests/Feature/Empresas/CompanyCreationTest.php tests/Feature/Ui/AdministrationLoadingFeedbackTest.php tests/Feature/Ui/DesignSystemComponentsTest.php` passed with 16 tests and 146 assertions.
- Guardrails verified: no CDN Tailwind, Material Symbols runtime, Google Fonts, inline scripts, fake fiscal export, fake payroll mass, fake employee counts, fake SAR verification, or fake city/regime data in `resources/views/livewire/empresas/index.blade.php`.
- Preserved contracts: `wire:model.live="search"`, create/edit navigation, `toggle(...)`, `delete(...)`, loading targets, delete confirmation, pagination, and the named keyboard scroll table region.

## Pending checks and risks

- Browser review remains pending for responsive table overflow, KPI wrapping, and action density on narrow screens.
- Native review pending at the time this file was updated; run over the current preview candidate before publishing any PR slice.
