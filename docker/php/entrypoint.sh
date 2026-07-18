#!/bin/sh
set -e

# Ensure Laravel writable directories are owned by the php-fpm user.
chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache
chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

# Run artisan commands as www-data to avoid creating root-owned files.
run_as_www_data() {
    su-exec www-data:www-data "$@"
}

# Run database migrations idempotently.
run_as_www_data php artisan migrate --force --ansi

# Seed production defaults (roles, permissions, super admin, first company) idempotently.
run_as_www_data php artisan db:seed --class=ProductionSeeder --force --ansi

# Cache framework artifacts for production performance.
run_as_www_data php artisan config:cache --ansi
run_as_www_data php artisan route:cache --ansi
run_as_www_data php artisan view:cache --ansi
run_as_www_data php artisan event:cache --ansi

# Keep the container alive by running php-fpm as root; workers will drop to www-data.
exec php-fpm
