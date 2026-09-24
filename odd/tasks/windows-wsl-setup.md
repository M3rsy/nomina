# Windows WSL Setup

## Goal
Provide a robust PowerShell entry point that provisions and starts Nómina through WSL2, Ubuntu, Docker Desktop, and Docker Compose.

## Tasks
- [x] Map existing setup scripts, Compose behavior, and documentation.
- [x] Implement a safe, idempotent PowerShell setup script.
- [x] Document Windows usage, clean/demo modes, and operational commands.
- [x] Verify script syntax, documented commands, and Docker configuration.

## Constraints
- Keep the repository inside the WSL Linux filesystem, not `/mnt/c`.
- Never overwrite an existing `.env` unless explicitly requested.
- Never delete Docker volumes or reset an existing database by default.
- Keep `.atl/` and `.pi/` out of the change.

## Evidence
- Confirmed Docker service host is `nomina-db`, app port is bound to `127.0.0.1:8000`, and the worker uses the `worker` profile.
- Confirmed the current manual flow requires recreating the app container after generating `APP_KEY`.
- Added `scripts/setup-windows.ps1` with WSL/tool validation, Linux-path enforcement, clone/update controls, deliberate APP_KEY recreation, PostgreSQL readiness retries, safe clean/demo provisioning, guarded reset, optional worker startup, and final service status.
- Added Windows PowerShell prerequisites and examples for clean, demo, worker, update, and destructive reset flows to `README.md`.
- Corrected Windows PowerShell 5.1 password transport: PowerShell now sends only ASCII Base64 over stdin, Bash removes transport carriage returns before decoding, and the UTF-8 admin password is never exposed in arguments, logs, or files.
- `git diff --check` passed for the script, README, and task artifact.
- Static assertions passed for WSL validation, safe defaults, clean/demo/reset modes, worker startup, and documentation examples.
- UTF-8/Base64 password transport passed a simulated CRLF round-trip with `contraseña-Ñ-✓`.
- `docker compose config --quiet` passed and the running app returned HTTP 302 to `/login`.
- Windows PowerShell plus WSL2 runtime execution remains pending because this verification host is Linux.
