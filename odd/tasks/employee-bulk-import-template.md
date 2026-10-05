# Employee bulk import template

## Goal
Add an official Excel template and bulk employee upload flow to the Employees module.

## Decisions
- Treat "Clave" as the existing `employees.payment_code` field shown in the employees table.
- Required import columns: employee code, key, hire date, first name, last name.
- Use PhpSpreadsheet already installed in the project.
- Use safe all-or-nothing import: rows are created only when the uploaded file has no validation errors.

## Tasks

- [x] 1. Add Excel template download endpoint and service
  - Evidence: `route('empleados.template')` returns `plantilla-empleados.xlsx` for authorized employee creators.
  - Files: `app/Http/Controllers/EmployeeImportController.php`, `app/Services/Employees/EmployeeBulkImportService.php`, `routes/web.php`.

- [x] 2. Add bulk import service with row validation
  - Evidence: valid rows create employees with schedule assignments; missing required fields, existing codes, duplicate in-file codes, duplicate normalized headers, invalid calendar dates, and workbooks exceeding the 1000-row or official-template-column limits fail safely.
  - Files: `app/Services/Employees/EmployeeBulkImportService.php`.

- [x] 3. Add Livewire import UI in Employees module
  - Evidence: employees page exposes template download and `.xlsx` import form with success summary and row-level errors.
  - Files: `app/Livewire/Empleados/Index.php`, `resources/views/livewire/empleados/index.blade.php`.

- [x] 4. Cover the flow with feature tests
  - Evidence: `tests/Feature/Empleados/EmployeeBulkImportTest.php` covers template download, successful import, required-field validation, duplicate protection, strict date validation, workbook row and column limits, and permission boundaries.

- [x] 5. Protect downloaded template date columns from Excel serial upload failures
  - Evidence: the official workbook keeps example date cells as explicit strings and formats date-entry ranges `C2:C1001` and `H2:H1001` as text (`@`) so editing and re-uploading does not turn them into unparseable serials.
  - Files: `app/Services/Employees/EmployeeBulkImportService.php`, `tests/Feature/Empleados/EmployeeBulkImportTest.php`.

## Verification
- Passed: `lerd php ./vendor/bin/pint --test app/Services/Employees/EmployeeBulkImportService.php tests/Feature/Empleados/EmployeeBulkImportTest.php`.
- Passed: `lerd php artisan test tests/Feature/Empleados/EmployeeBulkImportTest.php --filter="authorized employee creator can download the official Excel template"` — 1 test, 11 assertions.
- Passed: `lerd php artisan test tests/Feature/Empleados/EmployeeBulkImportTest.php` — 12 tests, 83 assertions.
- Passed: `git diff --check -- app/Services/Employees/EmployeeBulkImportService.php tests/Feature/Empleados/EmployeeBulkImportTest.php odd/tasks/employee-bulk-import-template.md`.
- Historical passed: `lerd php artisan test tests/Feature/Empleados/EmployeeCrudTest.php` — 21 passed, 107 assertions.

## Work-unit commits
- `f0338c7` — `fix(employees): preserve template date columns as text`.
