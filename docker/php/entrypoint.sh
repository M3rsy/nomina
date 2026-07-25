#!/bin/sh
set -e

if [ -z "${BACKUP_ARCHIVE_PASSWORD:-}" ]; then
    echo "ERROR: BACKUP_ARCHIVE_PASSWORD is required in the production runtime." >&2
    exit 1
fi

# Ensure Laravel writable directories are owned by the php-fpm user.
chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache
chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

# Run artisan commands as www-data to avoid creating root-owned files.
run_as_www_data() {
    su-exec www-data:www-data "$@"
}

if [ "${RUN_APP_BOOTSTRAP:-true}" = "true" ]; then
    # Run database migrations idempotently.
    run_as_www_data php artisan migrate --force --ansi

    # Seed production defaults (roles, permissions, super admin, first company) idempotently.
    run_as_www_data php artisan db:seed --class=ProductionSeeder --force --ansi

    # Cache framework artifacts for production performance.
    run_as_www_data php artisan config:cache --ansi
    run_as_www_data php artisan route:cache --ansi
    run_as_www_data php artisan view:cache --ansi
    run_as_www_data php artisan event:cache --ansi
fi

# Default to php-fpm while allowing other image roles to provide their own command.
if [ "$#" -eq 0 ]; then
    set -- php-fpm
fi

exec "$@"
