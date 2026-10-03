# Production-safe record retirement

## Goal
Make employee removal recoverable and make bulk/manual creation explain when an employee code belongs to a soft-deleted record, without physically deleting production history.

## Scope
- Employee directory: distinguish retired records from active/inactive records and allow authorized restoration.
- Employee creation and bulk import: detect same-company soft-deleted employee codes and guide the operator to restore the existing record.
- Preserve payroll/file soft-delete audit semantics; do not hard-delete production data in this work unit.
- Keep unrelated existing `package-lock.json` changes untouched.

## Tasks
- [x] Add employee restore authorization/action and retired-directory presentation.
- [x] Add soft-deleted employee-code guidance to manual and bulk creation.
- [x] Verify focused employee tests and record work-unit commit evidence.

## Decisions
- Soft deletion remains the normal production behavior.
- Restoration reuses the original employee identity and history instead of creating a duplicate code.
- Payroll and attendance-file restoration remain a separate follow-up because their related evidence/status lineage needs explicit design.

## Verification
- Passed `lerd php artisan test tests/Feature/Empleados/IndexTest.php`: 7 tests, 48 assertions.
- Passed `lerd php artisan test tests/Feature/Empleados/EmployeeCrudTest.php`: 23 tests, 118 assertions.
- Passed `lerd php artisan test tests/Feature/Empleados/EmployeePolicyTest.php`: 4 tests, 7 assertions.
- Passed `lerd php artisan test tests/Feature/Empleados/EmployeeBulkImportTest.php`: 12 tests, 76 assertions.
- Passed `git diff --check` for tracked and untracked employee changes.
- Passed `lerd php artisan test tests/Feature/Empleados/IndexTest.php tests/Feature/Empleados/EmployeeCrudTest.php tests/Feature/Empleados/EmployeePolicyTest.php tests/Feature/Empleados/EmployeeBulkImportTest.php`: 46 tests, 249 assertions.
- Passed Pint `--test` for all changed employee source and test files.

## Commits
- `4825ca8 feat(employees): make retirement recoverable`
