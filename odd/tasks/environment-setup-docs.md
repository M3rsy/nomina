# Environment Setup Docs

## Goal
Document separate Docker and Lerd environment templates and improve cross-platform setup instructions for Windows/WSL, Linux, and macOS.

## Tasks
- [x] Review current README setup flow and environment templates.
- [x] Improve README with clear recommended Docker/WSL path and alternatives.
- [x] Verify documentation/template consistency and Docker health.
- [x] Commit only intended files on main.

## Evidence
- Added `.env.docker.example` for Docker Compose with `DB_HOST=nomina-db`.
- Added `.env.lerd.example` for Lerd/native services with `DB_HOST=lerd-postgres`.
- Updated `README.md` with Windows/WSL, Linux, macOS Docker setup, clean/no-demo setup, and operational commands.
- Docker services verified running after the environment correction.
- Work-unit commit: `c076b50 docs: document docker and lerd setup`.
