# Arquitectura de Nómina

Resumen técnico de las fases de construcción y de la arquitectura vigente.

## Fases de construcción

| Fase | Alcance |
| --- | --- |
| Fase 0 | Esqueleto Laravel 12, Docker de desarrollo, autenticación base. |
| Fase 1 | Multi-tenant: modelo `Company`, `company_id` global scope, `CurrentCompany` service. |
| Fase 2 | Empleados, usuarios, roles (`super_admin`, `company_admin`) y permisos con spatie. |
| Fase 3 | Perfiles versionados de jornada, asignaciones efectivas por empleado y feriados por empresa. |
| Fase 4 | Carga inmutable de archivos de asistencia (GLG, ATTLOG), parser factory y correcciones normalizadas auditadas. |
| Fase 5 | Períodos de nómina, revisión obligatoria, procesamiento, reapertura auditada y estados terminales. |
| Fase 6 | Resolución de jornadas asignadas, análisis de asistencia, autorizaciones y cálculo exacto en minutos. |
| Fase 7 | Exportación de Excel: nómina consolidada y comprobantes individuales. |
| Fase 8 | Auditoría unificada (`AuditEntry`) y respaldos con spatie/laravel-backup. |
| Fase 9 | Preparación para producción: Docker Compose, nginx, php-fpm, SSL, deploy scripts, backups y health checks. |

## Arquitectura multi-tenant

- Cada tabla de dominio tiene `company_id` y un `GlobalScope` que filtra por `current_company_id()`.
- `CurrentCompany` service centraliza la empresa activa en sesión/test.
- Super admin usa `Model::withoutCompanyScope()` para ver cross-empresa.
- `app(\App\Services\CurrentCompany::class)->set($company)` es la forma correcta de establecer empresa en tests; nunca `current_company()->set()`.

## Módulos de asistencia y nómina

- `Attendance/ShiftOccurrenceResolver`: resuelve la jornada asignada efectiva y su fecha laboral, incluso cuando termina al día siguiente o cruza períodos de nómina.
- `Attendance/AttendanceShiftAnalyzer`: compara marcas de asistencia con la jornada, produce déficits y candidatos de hora extra completos, y conserva sus minutos y huellas exactas.
- `Attendance/OvertimeDecisionRecorder` y `Attendance/AttendanceExceptionRecorder`: guardan autorizaciones y excepciones como historiales inmutables y auditados.
- `Attendance/PayrollShiftEvaluationResolver`: ofrece a revisión, preparación y procesamiento una sola ruta para cargar y evaluar los hechos vigentes.
- `Attendance/PayrollShiftEvaluator`: reconoce el tiempo programado, aplica excepciones exactas y paga únicamente candidatos de hora extra autorizados.
- `Payroll/BandSplitter`: clasifica intervalos en minutos exactos según la banda salarial aplicable.
- `Payroll/PayrollProcessor`: transiciona el período y persiste resultados canónicos en minutos; las horas decimales son valores derivados.
- `Export`: genera Excel con phpoffice/phpspreadsheet a partir de los minutos persistidos, sin reprocesar marcas.

El TXT/DAT, cada línea importada y la identidad física del archivo son evidencia inmutable. Las asignaciones y correcciones actúan sobre registros normalizados y conservan actor, motivo, valores aplicados y fecha de auditoría. Una marca manual auditada solo incorpora una entrada o salida real omitida por el reloj, sin archivo, fila ni `raw_line`; no ingresa horas pagables ni reemplaza una excepción de asistencia.

## Estado de período de nómina

```text
draft → validating → ready → processing → processed → approved → exported
          ↑                         │
          └──── reapertura auditada┘
```

- La transición a `ready` se bloquea mientras falte una asignación de jornada, existan marcas ambiguas o pares incompletos, o haya candidatos de hora extra pendientes de autorización o rechazo.
- Los estados `processing`, `processed`, `approved`, `exported` y `cancelled` bloquean cualquier entrada que pueda alterar la asistencia calculada, incluidas marcas y asignaciones de jornada efectivas.
- La empresa de un empleado es inmutable después de crearlo porque identifica toda su historia de asistencia. Un traslado futuro debe modelar afiliaciones efectivas sin reescribir registros anteriores.
- Solo un período `processed` puede reabrirse. La reapertura exige permiso y motivo, elimina los resultados derivados obsoletos, registra actor y fecha, y devuelve el período a `validating`.
- `approved`, `exported` y `cancelled` permanecen inmutables; `cancelled` es un estado terminal fuera del flujo principal mostrado arriba.

## Seguridad

- `Gate::authorize()` en controladores; el `Controller` base no incluye `AuthorizesRequests`.
- La acción protegida `POST /empresa-activa` (`current-company.update`) cambia el contexto del `super_admin`; `CurrentCompany` lo aplica globalmente desde sesión.
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
