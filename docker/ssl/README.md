# SSL Options for Production

This project supports two SSL paths for the production Docker Compose stack.

## Option A: Let's Encrypt with Certbot (recommended)

The primary `docker-compose.prod.yml` includes a `certbot` service under the `ssl`
profile that requests certificates using the HTTP-01 webroot challenge.

1. Export your domain and contact email:
   ```bash
   export DOMAIN=planilla.tu-dominio.com
   export EMAIL=admin@tu-dominio.com
   ```

2. Start the nginx + certbot stack to obtain the certificate:
   ```bash
   docker compose -f docker-compose.prod.yml --profile ssl up -d
   docker compose -f docker-compose.prod.yml --profile ssl run --rm certbot
   ```

3. Reload nginx once the certificate exists:
   ```bash
   docker compose -f docker-compose.prod.yml exec web nginx -s reload
   ```

4. Set up a cron job to renew the certificate automatically:
   ```bash
   0 3 * * * /usr/bin/docker compose -f /var/www/nomina/docker-compose.prod.yml run --rm certbot renew && /usr/bin/docker compose -f /var/www/nomina/docker-compose.prod.yml exec web nginx -s reload
   ```

Certbot stores certificates in the `certbot-data` Docker volume and the
webroot challenge files in the `certbot-webroot` volume.

## Option B: Caddy (alternative)

If you prefer automatic HTTPS without managing certbot manually, use the
provided override file:

```bash
docker compose -f docker-compose.prod.yml -f docker-compose.caddy.yml up -d
```

This replaces the `web` service with an official `caddy` image that will
automatically provision and renew Let's Encrypt certificates based on the
`DOMAIN` environment variable.

## Self-signed or custom certificates

If you already have `fullchain.pem` and `privkey.pem` files, place them at
`/etc/letsencrypt/live/${DOMAIN}/` inside the `web` container (or bind-mount
them to that path) and restart nginx:

```bash
docker compose -f docker-compose.prod.yml exec web nginx -s reload
```
