# Guía de despliegue en producción

Esta guía explica cómo desplegar Nómina en un VPS con Docker Compose,
nginx, php-fpm, PostgreSQL y certificados SSL gratuitos de Let's Encrypt.

## Requisitos

- VPS con Ubuntu 22.04/24.04 LTS (o similar).
- Docker 24+ y Docker Compose plugin instalados.
- Dominio apuntando al VPS con registros A (y AAAA si aplica).
- Correo válido para notificaciones de Let's Encrypt.
- Puertos 80 y 443 abiertos.

## 1. Clonar el proyecto

```bash
cd /var/www
git clone https://github.com/tu-org/nomina.git nomina
cd nomina
```

## 2. Configurar variables de entorno

```bash
cp .env.production.example .env.production
nano .env.production
```

Completar obligatoriamente:

- `APP_KEY` — generar con `php artisan key:generate --show` (o dentro del contenedor).
- `APP_URL` — URL pública, por ejemplo `https://planilla.tu-dominio.com`.
- `DB_PASSWORD` y `POSTGRES_PASSWORD` — misma contraseña segura.
- `SUPER_ADMIN_PASSWORD` — contraseña inicial del super administrador.
- `DOMAIN` — dominio público, por ejemplo `planilla.tu-dominio.com`.
- `EMAIL` — correo para Let's Encrypt.

## 3. Crear directorios necesarios

```bash
mkdir -p storage/app/nomina-backups
mkdir -p bootstrap/cache
chmod -R 775 storage bootstrap/cache
```

## 4. Primera emisión de certificado SSL

```bash
export DOMAIN=planilla.tu-dominio.com
export EMAIL=admin@tu-dominio.com

# Levantar nginx para servir el desafío webroot.
docker compose -f docker-compose.prod.yml up -d web db app

# Solicitar certificado.
docker compose -f docker-compose.prod.yml --profile ssl run --rm certbot

# Recargar nginx para cargar el certificado.
docker compose -f docker-compose.prod.yml exec web nginx -s reload
```

## 5. Desplegar con el script automatizado

Después de la primera configuración manual del certificado:

```bash
./scripts/deploy.sh
```

El script:

1. Valida que existan `.env.production`, `DOMAIN`, `DB_PASSWORD` y `APP_KEY`.
2. Hace `git pull`.
3. Construye imágenes de producción.
4. Levanta servicios.
5. Ejecuta migraciones forzadas.
6. Ejecuta el `ProductionSeeder` (idempotente).
7. Optimiza Laravel (`config:cache`, `route:cache`, `view:cache`, `event:cache`).
8. Recarga nginx.

## 6. Post-despliegue

Verificar salud:

```bash
curl https://planilla.tu-dominio.com/health
```

Debe responder:

```json
{"status":"ok","checks":{"database":true,"storage":true,"cache":true}}
```

Iniciar sesión con el super admin configurado en `.env.production`.

## 7. Cron y tareas programadas

El stack incluye un servicio `scheduler` que ejecuta `crond` con el archivo
`docker/cron/nomina`. Cron solo invoca Laravel scheduler cada minuto. Laravel registra:

- `backup:run` cada hora.
- `backup:clean` diariamente a la 01:15.
- `backup:monitor` diariamente a la 01:30.

Las tres tareas usan bloqueo para evitar ejecuciones solapadas.
Los bloqueos expiran tras 120 minutos; si un proceso termina abruptamente, confirma que
no haya una tarea activa antes de liberar el bloqueo con `php artisan schedule:clear-cache`.

Alternativa: si prefieres cron en el host, agrega:

```cron
# Laravel scheduler
* * * * * cd /var/www/nomina && php artisan schedule:run >> /dev/null 2>&1
```

## 8. Renovación automática de certificados

Agrega un cron en el host:

```cron
0 3 * * * /usr/bin/docker compose -f /var/www/nomina/docker-compose.prod.yml --profile ssl run --rm certbot renew && /usr/bin/docker compose -f /var/www/nomina/docker-compose.prod.yml exec web nginx -s reload
```

## 9. Respaldos y restauración

Cada respaldo normal incluye el dump completo de PostgreSQL y los TXT/DAT originales de
`storage/app/private/uploads`. Se excluyen `.env*`, dependencias reinstalables, cachés,
logs, archivos temporales y el propio destino de respaldos.

> **Antes del primer despliegue con limpieza programada:** ejecuta `backup:list`,
> inventaría los ZIP históricos y copia fuera del volumen cualquier archivo que deba
> conservarse. `backup:clean` aplicará automáticamente la política de retención.

### Crear respaldo manual

```bash
docker compose -f docker-compose.prod.yml exec app php artisan backup:run
```

### Listar respaldos

```bash
docker compose -f docker-compose.prod.yml exec app php artisan backup:list
```

### Descargar respaldo

```bash
docker compose -f docker-compose.prod.yml exec app php artisan backup:list
# Copiar desde el volumen o desde storage/app/nomina-backups.
```

### Restaurar

spatie/laravel-backup no incluye restauración automática. Para restaurar:

1. Detener el servicio `app`.
2. Extraer el ZIP de respaldo.
3. Restaurar el dump `.sql` en PostgreSQL con `psql` o `pg_restore`.
4. Restaurar `storage/app/private/uploads` desde el ZIP.
5. Reiniciar el contenedor.

## 10. Actualización

```bash
cd /var/www/nomina
./scripts/deploy.sh
```

## 11. Troubleshooting

### nginx no encuentra el certificado

Asegúrate de que el dominio en `DOMAIN` coincida con el certificado generado:

```bash
docker compose -f docker-compose.prod.yml exec web ls -la /etc/letsencrypt/live/
```

### Permisos en storage

```bash
docker compose -f docker-compose.prod.yml exec app chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache
```

### Contenedores no levantan

```bash
docker compose -f docker-compose.prod.yml logs -f
```

### Configuración de nginx inválida

```bash
docker compose -f docker-compose.prod.yml exec web nginx -t
```

## Alternativa: Caddy

Si prefieres HTTPS automático sin gestionar certbot, usa:

```bash
export DOMAIN=planilla.tu-dominio.com
docker compose -f docker-compose.prod.yml -f docker-compose.caddy.yml up -d
```

Ver detalles en [`docker/ssl/README.md`](docker/ssl/README.md).

## Referencias

- [Laravel Deployment](https://laravel.com/docs/deployment)
- [Docker Compose](https://docs.docker.com/compose/)
- [Certbot](https://eff-certbot.readthedocs.io/)
- [spatie/laravel-backup](https://github.com/spatie/laravel-backup)
