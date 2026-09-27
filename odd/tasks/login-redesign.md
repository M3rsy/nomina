# Login redesign

Feature: login-redesign

Approved issue: #369

Source reference: Stitch project `5126769435574810571`, screen `d4c9df8f936c4dcb9bdb6116bb192f6a` (`Iniciar Sesión - Rediseño UI/UX Nómina Executive`).

## Tasks

- [x] Update the authentication shell to match the Stitch login composition, left assurance panel, right auth panel, spacing, and footer.
- [x] Update login form copy and controls to match the target design while preserving Livewire behavior and validation.
- [x] Run focused verification for Blade/PHP syntax and frontend build where available.

## Constraints

- Preserve `wire:submit="login"`, the existing email/password models, validation messages, and password-reset route.
- Do not introduce remember-me behavior because the current Livewire component does not expose or authenticate with a remember property.
- Keep the corporate access control visual-only because no SSO route exists.
- Do not change authentication backend code or routes.

## Evidence

- Existing `App\Livewire\Auth\Login` supports only `email` and `password`; `Auth::attempt` explicitly uses `remember: false`.
- No application SSO route or provider integration was found during scoped inspection.
- Implemented the responsive two-panel shell, operational assurance content, secure-login framing, icon-bearing fields, reset-password link, primary CTA, and disabled visual-only SSO control.
- `php artisan view:clear` completed successfully: compiled views cleared.
- `git diff --check` passed.
- `php artisan test tests/Feature/Auth/AuthPresentationTest.php tests/Feature/WelcomeRouteTest.php tests/Feature/Auth/LoginTest.php` passed: 25 tests, 184 assertions.
- `php artisan view:cache` passed: Blade templates compiled successfully.
- `npm run build` was not run because Vite's Laravel plugin writes generated assets to `public/build`, which was outside the authorized edit surfaces during implementation.
- Visual QA was approved by the user before delivery.
