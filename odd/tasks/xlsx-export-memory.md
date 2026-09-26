# XLSX Export Memory

## Goal
Make the payroll stub XLSX export safe under the full test suite's accumulated memory pressure.

## Scope
- Diagnose and fix the memory exhaustion observed in `ComprobanteDownloadTest` during full `php artisan test`.
- Preserve export behavior, authorization, response headers, and temporary file deletion semantics.
- Add a focused regression if there is a correct seam.

## Non-goals
- Do not redesign payroll reports.
- Do not change payroll calculations or XLSX contents beyond memory lifecycle management.
- Do not touch unrelated untracked `.mcp*` or `.pi/` files.

## Tasks
- [x] Add/tighten a regression loop for repeated comprobante XLSX generation.
- [x] Fix exporter memory lifecycle.
- [x] Verify the focused test file.
- [ ] Verify a broader Nomina subset or the full suite if time permits.

## Evidence
- The original focused test file passed alone, while the full suite previously died in `vendor/maennchen/zipstream-php/src/File.php` with PHP memory limit `128M` while writing the comprobante XLSX after many prior tests.
- Regression: 24 exports run with automatic cycle collection disabled, temporary artifacts are removed after every export, and retained memory must remain below 3 MiB.
- Before lifecycle cleanup, `php artisan test tests/Feature/Nomina/ComprobanteDownloadTest.php` failed the tightened regression: retained memory was 2,214,216 bytes against the initial 1 MiB bound after 12 exports.
- `PayrollStubExporter::export()` now releases the XLSX writer in the write callback and always disconnects worksheets/releases local workbook references in `finally`, including exceptional paths.
- After cleanup and final regression calibration, `php artisan test tests/Feature/Nomina/ComprobanteDownloadTest.php` passed: 10 tests, 39 assertions.
- `npm run build` passed.
- Full suite under the harness default `128M` still exhausts memory in ZipStream; the focused regression passes. The export-focused suite passes with `php -d memory_limit=512M vendor/bin/pest --filter=ComprobanteDownloadTest`.
- Commits: pending (owned by the parent orchestrator).
