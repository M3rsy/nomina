# Employee bulk import hardening

## Goal
Harden employee bulk imports with operational auditability, clearer grouped validation feedback, and database-backed duplicate/concurrency protection.

## Tasks
- [x] Add import batch persistence/audit evidence.
- [x] Group repeated import errors for readable Livewire feedback.
- [x] Handle concurrent duplicate employee codes from the DB unique constraint.
- [x] Add focused tests and run import/security regressions.

## Evidence
- Branch: `harden/employee-bulk-import`.
- `employee_import_batches` records filename, actor, row counts, status, and non-sensitive error summary/details for every service-level import attempt.
- Livewire groups identical messages and preserves all affected spreadsheet row numbers.
- Employee unique-constraint violations are detected across SQLite/MySQL/PostgreSQL-style driver errors, translated to Spanish validation feedback, and rolled back atomically.
- `lerd php artisan test tests/Feature/Empleados/EmployeeBulkImportTest.php`: 11 passed (65 assertions).
- `lerd php artisan test tests/Feature/Empleados/EmployeeCrudTest.php`: 21 passed (107 assertions).
- Focused Pint check for changed files: passed.
- Full requested Pint command is currently blocked by the pre-existing `app/Models/User.php` `ordered_imports` violation.
