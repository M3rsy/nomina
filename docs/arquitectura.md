# Arquitectura de Nómina

Resumen técnico del proyecto en sus nueve fases de construcción.

## Fases de construcción

| Fase | Alcance |
| --- | --- |
| Fase 0 | Esqueleto Laravel 12, Docker de desarrollo, autenticación base. |
| Fase 1 | Multi-tenant: modelo `Company`, `company_id` global scope, `CurrentCompany` service. |
| Fase 2 | Empleados, usuarios, roles (`super_admin`, `company_admin`) y permisos con spatie. |
| Fase 3 | Jornadas y feriados por empresa, scoped al `company_id`. |
| Fase 4 | Carga de archivos de asistencia (GLG, ATTLOG), parser factory. |
| Fase 5 | Períodos de nómina y flujo de estados (`draft → validating → ready → processed → approved → exported → cancelled`). |
| Fase 6 | Motor de cálculo de planilla, `BandSplitter`, `PayrollCalculator`, `PayrollProcessor`. |
| Fase 7 | Exportación de Excel: nómina consolidada y comprobantes individuales. |
| Fase 8 | Auditoría unificada (`AuditEntry`) y respaldos con spatie/laravel-backup. |
| Fase 9 | Preparación para producción: Docker Compose, nginx, php-fpm, SSL, deploy scripts, backups y health checks. |

## Arquitectura multi-tenant

- Cada tabla de dominio tiene `company_id` y un `GlobalScope` que filtra por `current_company_id()`.
- `CurrentCompany` service centraliza la empresa activa en sesión/test.
- Super admin usa `Model::withoutCompanyScope()` para ver cross-empresa.
- `app(\App\Services\CurrentCompany::class)->set($company)` es la forma correcta de establecer empresa en tests; nunca `current_company()->set()`.

## Separación de servicios

- `Payroll/BandSplitter`: divide franjas horarias según reglas hondureñas.
- `Payroll/PayrollCalculator`: calcula ordinarias, extras y faltas por día.
- `Payroll/PayrollProcessor`: transiciona el período y persiste resultados.
- `Export`: genera Excel con phpoffice/phpspreadsheet.

## Estado de período de nómina

```
draft → validating → ready → processed → approved → exported → cancelled
```

Un período en estados `approved`, `exported` o `cancelled` está bloqueado para edición de marcas.

## Seguridad

- `Gate::authorize()` en controladores; el `Controller` base no incluye `AuthorizesRequests`.
- `set-active-company` middleware asigna contexto de empresa.
- SSL vía Let's Encrypt (certbot) o Caddy como alternativa.
- Variables sensibles en `.env.production`; nunca en el código.

## Limitaciones conocidas

- La restauración de respaldos es manual: spatie/laravel-backup crea ZIPs pero no provee restore automático.
- Cada respaldo contiene la base de datos completa; no separamos por empresa aún.
- El portal de comprobantes para empleados aún no existe.
- Deducciones y salario final aún no se calculan en el motor de planilla.

## Roadmap post-MVP

- Deducciones configurables (IHSS, rap, ISR, préstamos, etc.).
- Salario neto y generación de comprobantes finales.
- Restauración asistida de respaldos por UI.
- Portal de empleados para descargar comprobantes.
- Notificaciones por correo de períodos cerrados y faltas.
- Métricas de dashboard y exportación de reportes históricos.
