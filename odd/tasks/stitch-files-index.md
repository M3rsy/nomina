# Stitch Files Index

## Goal

Migrate only the uploaded attendance files index (`/archivos`) toward the Google Stitch files module design while preserving Livewire behavior and upload/delete logic.

## Non-goals

- Do not modify upload ingestion logic.
- Do not modify services, models, policies, authorization, routes, payroll rules, or raw mark processing.
- Do not modify `/archivos/subir` or `/archivos/{file}` in this slice unless explicitly requested later.
- Do not introduce React, Vue, Next.js, CDN Tailwind, Material Symbols, remote fonts, or another frontend framework.
- Do not copy Stitch HTML indiscriminately.
- Do not invent fake metrics, fake files, fake users, fake sync data, or fake inline upload behavior.

## Constraints

- Preserve `wire:model.live.debounce.300ms="search"`, `wire:model.live="status"`, `wire:model.live="pay_period_id"`, `wire:model.live="from"`, `wire:model.live="to"`, and `wire:click="clearFilters"`.
- Preserve upload navigation through `route('archivos.upload')`.
- Preserve `route('archivos.show', $file)`, replace upload route, and delete action permissions.
- Preserve pagination and tenant scoping.
- Preserve delete modal behavior, validation, and loading button.
- Summary cards must derive only from `$statusCounts`, `$files`, and existing visible data.
- Use TDD: behavior test first, then Blade changes.

## Tasks

- [x] Add Feature coverage for the Stitch-inspired files index workspace, real navigation, summary, filters, and listing.
- [x] Migrate `resources/views/livewire/archivos/index.blade.php` using existing UI components and semantic tokens.
- [x] Run focused files/UI tests.
- [x] Record verification evidence and browser-review risks.

## Evidence

- Branch: `preview/stitch-ui-combined`
- Scope: `/archivos` index only.
- RED: `php artisan test tests/Feature/Archivos/IndexTest.php --filter='company admin sees the uploaded files management workspace'` failed because the prior view did not render `Control de Asistencia / Relojes y Biometría`.
- GREEN: the same focused test passed with 15 assertions after the Blade migration.
- Verification: `php artisan test tests/Feature/Archivos/IndexTest.php tests/Feature/FileIsolationTest.php tests/Feature/Ui/DesignSystemComponentsTest.php` passed with 28 tests and 164 assertions.
- Preserved contracts: tenant-scoped listing, pagination, all Livewire filter bindings, active chips, show/replace/delete authorization, and the delete confirmation form/loading target.
- Commit: intentionally not created for this preview branch, per task instruction.

## Pending checks and risks

- Browser review remains pending for responsive table overflow, KPI wrapping with the project status set, and modal focus/visual behavior.
- The file-count summary is company-wide from `$statusCounts`; the listing result count is filter-aware from `$files->total()`.
