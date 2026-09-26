# Stitch Work Schedules Index

## Goal

Migrate only the work schedules index (`/jornadas`) toward the Google Stitch jornadas design while preserving Livewire behavior, profile versioning, permissions, historical-impact safeguards, and payroll calculation semantics.

## Non-goals

- Do not modify backend logic, services, models, policies, routes, payroll rules, overtime rules, profile publication, or retirement behavior.
- Do not introduce React, Vue, Next.js, CDN Tailwind, Material Symbols, remote fonts, inline scripts, or another frontend framework.
- Do not copy Stitch HTML indiscriminately.
- Do not invent fake schedules, fake history, fake employee counts, fake technical sync timestamps, or fake quick-turn actions.
- Do not make per-schedule overtime bands editable.

## Constraints

- Preserve all operational actions and loading targets: `confirmHistoricalSave`, `openCreateProfile`, `openRetireProfile(...)`, `createProfile`, `retireProfile`, `activateGeneralProfile`, and `save`.
- Preserve `wire:model.live="selectedProfileId"` and schedule row bindings for working day, start/end time, base ordinary hours, and notes.
- Preserve migration warning, historical impact warning, create profile form, retire profile form, success alerts, profile history, and no-active-company behavior.
- Preserve permission gating for management controls.
- Use real computed values only: working days count, weekly ordinary hours, profile list/history, technical readiness items, historical impact context, and `$timeBandProfile`.
- Use TDD: behavior test first, then Blade changes.

## Tasks

- [x] Add/adjust Feature coverage for the Stitch-inspired Jornadas structure.
- [x] Migrate `resources/views/livewire/jornadas/index.blade.php` using existing UI components and semantic tokens.
- [x] Run focused Jornadas/UI tests.
- [x] Record verification evidence and browser-review risks.

## Evidence

- Branch: `preview/stitch-ui-combined`
- Scope: `/jornadas` index only.
- RED: `php artisan test tests/Feature/Fase3/JornadasFeriadosTest.php --filter='work schedules page presents'` failed because the prior view did not render `data-work-schedules-index="workspace"`.
- GREEN: the same focused test passed with 1 test and 15 assertions after the Blade migration.
- Focused verification: `php artisan test tests/Feature/Fase3/JornadasFeriadosTest.php tests/Feature/Ui/OperationsLoadingFeedbackTest.php tests/Feature/Ui/DesignSystemComponentsTest.php` passed with 29 tests and 182 assertions.
- Preview regression verification: `php artisan test tests/Feature/Fase3/JornadasFeriadosTest.php tests/Feature/Archivos/IndexTest.php tests/Feature/FileIsolationTest.php tests/Feature/Empleados/IndexTest.php tests/Feature/Empleados/EmployeeCrudTest.php tests/Feature/Nomina/IndexTest.php tests/Feature/Ui/OperationsLoadingFeedbackTest.php tests/Feature/Ui/DesignSystemComponentsTest.php` passed with 107 tests and 624 assertions.
- Guardrails verified: no CDN Tailwind, Material Symbols runtime, Google Fonts, inline scripts, fake technical sync timestamp, fake employee count, or fake editable recargo actions in `resources/views/livewire/jornadas/index.blade.php`.
- Preserved contracts: profile selector, schedule row bindings, migration warning, historical impact flow, create/retire profile flows, activation/save buttons, profile history, permission gating, and operation loading targets.

## Pending checks and risks

- Browser review remains pending for responsive table overflow, side-panel wrapping, and modal/panel visual hierarchy.
- Native review pending at the time this file was updated; run over the current preview candidate before publishing any PR slice.
