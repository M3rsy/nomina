#!/bin/bash
set -euo pipefail

# Production deployment script for Nómina.
# Run from the project root on the VPS after configuring .env.production.

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"
COMPOSE_FILE="$PROJECT_DIR/docker-compose.prod.yml"

cd "$PROJECT_DIR"

# -----------------------------------------------------------------------------
# Validate required environment values.
# -----------------------------------------------------------------------------
if [ ! -f .env.production ]; then
    echo "ERROR: .env.production is missing. Copy .env.production.example and fill the secrets."
    exit 1
fi

# shellcheck source=/dev/null
source .env.production

missing=()
[ -z "${DOMAIN:-}" ] && missing+=("DOMAIN")
[ -z "${DB_PASSWORD:-}" ] && missing+=("DB_PASSWORD")
[ -z "${APP_KEY:-}" ] && missing+=("APP_KEY")

if [ ${#missing[@]} -ne 0 ]; then
    echo "ERROR: The following required variables are missing in .env.production: ${missing[*]}"
    exit 1
fi

# -----------------------------------------------------------------------------
# Deploy.
# -----------------------------------------------------------------------------
echo "==> Pulling latest code..."
git pull origin main

echo "==> Building production images..."
docker compose -f "$COMPOSE_FILE" build

echo "==> Starting production services..."
docker compose -f "$COMPOSE_FILE" up -d

echo "==> Running database migrations..."
docker compose -f "$COMPOSE_FILE" exec app php artisan migrate --force --ansi

echo "==> Running production seeder (idempotent)..."
docker compose -f "$COMPOSE_FILE" exec app php artisan db:seed --class=ProductionSeeder --force --ansi

echo "==> Optimizing Laravel..."
docker compose -f "$COMPOSE_FILE" exec app php artisan optimize --ansi

echo "==> Reloading nginx..."
docker compose -f "$COMPOSE_FILE" exec web nginx -s reload || true

echo "==> Deployment status..."
docker compose -f "$COMPOSE_FILE" ps

echo "==> Done. Visit https://${DOMAIN}/health to verify."
