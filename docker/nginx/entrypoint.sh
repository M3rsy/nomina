#!/bin/sh
set -e

# Substitute environment variables into nginx site configuration.
envsubst '$DOMAIN' < /etc/nginx/conf.d/default.conf > /etc/nginx/conf.d/default.conf.replaced
mv /etc/nginx/conf.d/default.conf.replaced /etc/nginx/conf.d/default.conf

# Validate the generated configuration before starting.
nginx -t

exec nginx -g 'daemon off;'
