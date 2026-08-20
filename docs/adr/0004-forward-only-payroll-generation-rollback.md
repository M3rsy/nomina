# Repliegue hacia adelante de generaciones de nómina

**Estado:** aceptada
**Fecha:** 2026-08-19
**Complementa:** [ADR 0002 — Nómina basada primero en duración](0002-duration-first-payroll.md)

## Contexto

La migración que introdujo generaciones e instantáneas diarias reemplazó la unicidad histórica por una unicidad que incluye `result_generation`. Una nómina reabierta puede conservar varias filas para el mismo empleado y fecha, enlazadas mediante `supersedes_id`.

Eliminar esas columnas y restaurar la unicidad anterior no puede representar esa historia. Borrar, deduplicar o apartar filas permitiría el descenso técnico, pero destruiría o escondería evidencia auditada y contradiría el repliegue hacia adelante adoptado en el ADR 0002.

## Decisión

El descenso de `2026_07_30_000005_add_payroll_day_snapshot_to_payroll_results` falla cerrado antes de ejecutar DDL cuando existe cualquier estado que solo el esquema versionado puede representar:

- un período cuya generación actual no sea `1` o que tenga una generación autorizada;
- un resultado cuya generación no sea `1` o que sustituya otro resultado;
- una instantánea diaria o su hash almacenado.

El error indica conservar la migración y aplicar una corrección hacia adelante. La migración solo puede descender cuando no existe ese estado, por ejemplo en una instalación sin uso o con filas heredadas de generación `1` sin instantánea.

En PostgreSQL, el descenso bloquea ambas tablas durante la transacción antes de consultar el estado. Así evita que una escritura concurrente aparezca entre la verificación y el cambio de esquema.

## Consecuencias

- El intento rechazado conserva esquema, datos y registro de migración sin cambios.
- No se elimina, actualiza, deduplica, aparta ni reconstruye historia de nómina.
- Un operador debe detener el descenso y desplegar una migración correctiva nueva.
- El descenso seguro continúa disponible para bases sin historia dependiente del esquema versionado.
- Las pruebas PostgreSQL limpian exclusivamente la base aislada mediante `db:wipe`; no usan el descenso de producción como mecanismo de limpieza.
