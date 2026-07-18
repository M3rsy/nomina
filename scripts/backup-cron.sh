#!/bin/bash
set -euo pipefail

# Cron wrapper to run a Spatie Laravel backup inside the production app container.

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"
COMPOSE_FILE="$PROJECT_DIR/docker-compose.prod.yml"

/usr/bin/docker compose -f "$COMPOSE_FILE" exec -T app php artisan backup:run --ansi
