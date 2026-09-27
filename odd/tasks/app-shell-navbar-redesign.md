# App shell navbar redesign

Feature: app-shell-navbar-redesign

Approved issue: #370

Source reference: user screenshots from current app navbar and Stitch navbar example in project `5126769435574810571`, screen `900655473792684198`.

## Tasks

- [x] Redesign the authenticated app shell header as an operational context bar aligned with the Stitch visual language.
- [x] Refine desktop sidebar header/company/account areas to reduce duplication and match the login redesign tone.
- [x] Preserve navigation, company switching, account menu, and mobile menu behavior.
- [x] Run focused Blade/auth layout verification without committing or opening a PR.

## Constraints

- Delivery remained blocked until the user completed visual QA and explicitly approved issues, commits, and pull requests.
- Keep existing Laravel routes, Livewire logout, company switching forms, and Alpine interactions.
- Do not add new backend state for payroll period or status; visual status copy must remain generic and not claim unverified SLA.

## Evidence

- Added a refined `Nómina` / `Executive v2.4` brand treatment, softer grouped navigation, a secondary sidebar company context control, and a premium account footer.
- Reworked the desktop header into an operational context bar that derives the active module from existing navigation data, keeps the super-admin company switcher primary, and uses only generic status copy.
- Added a compact active-context panel to the existing mobile drawer without changing its Alpine disclosure behavior, company forms, navigation routes, ARIA labels, or Livewire logout.
- Read-only Blade compile/parse check passed using Laravel's Blade compiler in memory.
- `git diff --check` passed.
- `php artisan test tests/Feature/Navigation/AuthenticatedNavigationTest.php` passed: 11 tests, 126 assertions.
- `php artisan view:cache` passed: Blade templates cached successfully.
- `npm run build` intentionally skipped because it can write generated assets outside the allowed edit surfaces.
- Visual QA was approved by the user before delivery.
