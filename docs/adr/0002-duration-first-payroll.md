# Nómina basada primero en duración

**Estado:** aceptada
**Fecha:** 2026-08-03
**Supersede:** [ADR 0001 — Resolver la nómina desde la jornada asignada](0001-resolve-payroll-by-assigned-shift.md)

## Decisión

Las publicaciones nuevas del perfil general resuelven nómina con `duration-first-v2`: el tiempo observado satisface primero una cuota ordinaria diaria y solo el excedente entra en bandas adicionales. `schedule-overlap-v1` permanece disponible para toda publicación heredada; no se recalcula ni se migra su historia.

La identidad inmutable de publicación, compuesta por el identificador publicado y `payroll_policy_key`, es la única puerta de despacho. La forma de la jornada, `profile_key`, la versión del perfil, `definition_hash` y `rules_version` no seleccionan comportamiento.

## Activación y resolución efectiva

- El publicador crea una versión general 06:00–14:00 de lunes a sábado, con domingo no laborable, efectiva desde el primer período de nómina configurado que todavía no haya comenzado.
- La publicación usa un intervalo semiabierto `[effective_from,effective_to)`. Su identidad y contenido son inmutables; solo puede cerrarse una publicación abierta bajo bloqueo para dar paso a la siguiente.
- La activación reasigna prospectivamente a los empleados actuales. Altas posteriores consultan exclusivamente el historial efectivo en su fecha de asignación.
- Cada fecha laboral debe resolver exactamente una asignación, una publicación y una jornada de la misma empresa. Cero o múltiples resultados bloquean revisión y procesamiento; no se elige por orden de creación, bandera activa ni primera coincidencia.
- Solicitudes equivalentes convergen en la misma publicación y asignaciones. Conflictos de identidad, referencia o solapamiento fallan de forma atómica.

## Reconocimiento diario

El intervalo observado se cuantiza una sola vez a minutos completos y conserva los instantes y revisiones de sus marcas.

- De lunes a sábado no feriado, los primeros `min(observado, 480)` minutos son ordinarios, cualquiera sea la entrada.
- El excedente se parte por hora local: +75 % `[00:00,06:00)`, +25 % `[06:00,18:00)` y +50 % `[18:00,24:00)`.
- Domingo o feriado, determinado por fecha laboral, reconoce todo +100 % y no crea cuota ordinaria, déficit, variación ni candidato adicional.
- Después de por lo menos una hora extra completa, solo un residuo de 1 a 30 minutos se excluye como transferencia; la salida observada queda intacta.

## Concurrencia y transacciones

`PayrollContextLocker` es el único propietario de la transacción de nómina. Bloquea por identificador ascendente en el orden canónico:

`company → payPeriods → profiles → publications → employees → assignments → rawMarks`

Los analizadores y procesadores trabajan dentro de ese contexto y no abren transacciones ni bloqueos anidados. El trabajo en cola confirma sus estados en transacciones cortas separadas antes y después del procesamiento; ninguna transacción del estado del trabajo envuelve la transacción de nómina.

## Decisiones auditadas

Los hechos detectados no se editan. Las decisiones y reconocimientos son anexos auditados con actor, motivo, instante, huella y, cuando corresponde, vínculo al registro que sustituyen.

- Un `daily_shortfall` pendiente bloquea. Conceder paga sus minutos ordinarios; rechazar cierra sin pago; revocar solo una concesión restaura el estado pendiente.
- Una variación posterior a las 06:20 se emite únicamente al completar la cuota. Su reconocimiento es informativo, no bloquea y no cambia el pago.
- El candidato de hora extra completo permanece inmutable. Puede aprobarse o rechazarse entero, o aprobarse individualmente un subintervalo contiguo de minutos completos; el complemento queda rechazado y conserva sus bandas. Los lotes solo deciden candidatos completos.
- Una huella que ya no coincide con publicación, asignación, jornada, calendario, marcas o revisiones no agrega ninguna decisión.

## Historia e informes

Cada resultado nuevo contiene una instantánea diaria inmutable y versionada con la identidad publicada, `rules_version`, marcas y revisiones, minutos por banda, déficit, candidato y decisión de hora extra, complemento rechazado, variación, reconocimiento y transferencia excluida. Su hash permite reutilizar un reintento idéntico; una diferencia falla sin sobrescribir el resultado existente.

Los exportadores reproducen resultados actuales desde esa instantánea. Las filas anteriores permanecen sin cambios, se etiquetan `LEGACY` y muestran nulo o blanco para hechos que nunca almacenaron. Los subtotales por empleado y totales del documento suman minutos enteros antes de convertir a horas decimales; el redondeo de presentación nunca alimenta otro total.

## Repliegue y evolución

El repliegue es hacia adelante: se detienen activaciones futuras o el procesamiento con `duration-first-v2`, pero no se borran publicaciones, decisiones, reconocimientos, instantáneas ni resultados ya procesados. No se hace actualización destructiva, recálculo histórico ni backfill de datos desconocidos. Una corrección requiere una nueva publicación, decisión, generación o versión de instantánea, según el hecho que cambie.

## Consecuencias y compromisos

| Aspecto | Consecuencia aceptada |
|---|---|
| Flexibilidad | La hora de entrada deja de reducir pago cuando se completa la cuota; la puntualidad queda como variación auditable. |
| Integridad | La resolución exacta y el bloqueo canónico prefieren fallar cerrados antes que elegir datos ambiguos. |
| Auditoría | La conservación anexada e inmutable aumenta almacenamiento, pero permite reconstruir qué se decidió y publicó. |
| Concurrencia | El orden único de bloqueos reduce carreras y bloqueos mutuos a cambio de serializar operaciones sobre el mismo contexto. |
| Historia | `LEGACY` distingue desconocido de cero; los informes históricos pueden tener celdas vacías en lugar de valores inferidos. |
| Operación | No existe rollback destructivo: las correcciones se publican o registran como hechos nuevos. |
