# Asistencia y nómina

Este contexto transforma marcas de asistencia en tiempo reconocido para nómina sin alterar los hechos registrados por el reloj. La política aplicable se resuelve por identidad publicada y fecha laboral; nunca se deduce por la forma del horario.

## Lenguaje

**Marca de asistencia**:
Registro normalizado de entrada o salida usado para resolver una fecha laboral. Puede ser una marca observada o una marca manual auditada.
_Evitar_: Hora trabajada, hora pagable

**Marca observada**:
Registro real de entrada o salida producido por el reloj de asistencia. Su instante y sus revisiones son evidencia inmutable.
_Evitar_: Marca manual, ajuste de horas

**Marca manual auditada**:
Registro de una entrada o salida real que el reloj omitió, incorporado con actor, motivo e instante de auditoría sin crear evidencia de archivo.
_Evitar_: Marca observada, excepción de asistencia

**Jornada asignada**:
Versión de horario aplicable a un empleado en una fecha determinada. Identifica el contexto de resolución, pero no selecciona por sí sola la política de nómina.
_Evitar_: Jornada de empresa, horario más reciente

**Perfil general**:
Historia efectiva por fecha de la jornada predeterminada de una empresa. Cada activación crea una versión; no reemplaza retrospectivamente las anteriores.
_Evitar_: Perfil activo único, horario global mutable

**Publicación de política de nómina**:
Registro efectivo en el intervalo `[effective_from,effective_to)` que vincula un perfil con una política de cálculo publicada.
_Evitar_: Configuración inferida, bandera activa

**Identidad de política de nómina**:
Par inmutable formado por la publicación y su `payroll_policy_key`. Es la única puerta de despacho entre `schedule-overlap-v1` y `duration-first-v2`.
_Evitar_: `rules_version`, forma del horario, versión del perfil

**Fecha laboral**:
Fecha en la que comienza una jornada, incluso cuando termina al día siguiente. Domingos y feriados se determinan con esta fecha.
_Evitar_: Fecha calendario de salida

**Tiempo observado**:
Minutos completos transcurridos entre las marcas vigentes, cuantizados una sola vez sin modificar sus instantes.
_Evitar_: Tiempo pagable, horas redondeadas

**Cuota ordinaria**:
Primeros `min(tiempo observado, 480)` minutos reconocidos como ordinarios por `duration-first-v2` de lunes a sábado no feriado, sin depender de la hora de entrada.
_Evitar_: Tiempo programado, solapamiento con la jornada

**Déficit diario**:
Hecho no intervalar `daily_shortfall` de `480 - tiempo observado` minutos cuando no se completa la cuota ordinaria. Existe como máximo uno por fecha laboral elegible.
_Evitar_: Llegada tardía, salida anticipada, ausencia parcial

**Excepción de asistencia**:
Decisión auditada sobre un déficit diario. Conceder agrega sus minutos al tiempo pagable ordinario; rechazar lo cierra sin pago; revocar una concesión restaura el estado pendiente.
_Evitar_: Corrección de marca, permiso informal

**Variación de entrada**:
Hecho informativo emitido cuando la entrada es posterior a las 06:20 y se completan 480 minutos. No bloquea ni modifica el tiempo pagable.
_Evitar_: Penalización por tardanza, déficit diario

**Reconocimiento de variación**:
Anexo auditado con actor, motivo, instante y huella vigente que deja constancia de una variación de entrada sin alterar asistencia ni pago.
_Evitar_: Aprobación, corrección de marca

**Candidato de hora extra**:
Tramo inmutable posterior a la cuota ordinaria, clasificado por bandas salariales, que todavía no genera tiempo pagable.
_Evitar_: Hora extra pagada, ajuste manual

**Decisión de hora extra**:
Anexo auditado que aprueba o rechaza el candidato completo, o aprueba un único subintervalo contiguo de minutos completos y rechaza su complemento. Las decisiones por lote solo aceptan candidatos completos.
_Evitar_: Edición del candidato, aprobación parcial por lote

**Transferencia excluida**:
Residuo de 1 a 30 minutos posterior a por lo menos una hora extra completa que se conserva en la evidencia de salida, pero no en el candidato pagable.
_Evitar_: Redondeo general, modificación de salida

**Tiempo pagable**:
Minutos finalmente reconocidos para nómina y clasificados como ordinarios, +25 %, +50 %, +75 % o +100 %.
_Evitar_: Tiempo observado, horas decimales acumuladas

**Instantánea diaria de nómina**:
Registro inmutable por empleado y fecha laboral que congela identidad publicada, marcas y revisiones, decisiones, auditorías, bandas, transferencia excluida y versión de reglas.
_Evitar_: Vista recalculada, caché mutable

**Fila heredada**:
Resultado anterior a la instantánea versionada. Se presenta como `LEGACY`; los datos que nunca fueron guardados permanecen nulos o en blanco.
_Evitar_: Fila incompleta con ceros inferidos, fila recalculada

## Contrato operativo

### Resolución y activación

- Cada fecha laboral debe resolver exactamente una asignación, una publicación del mismo perfil y empresa, y una jornada del día; cero o más de una coincidencia bloquean revisión y procesamiento. Nunca se elige la primera fila.
- `schedule-overlap-v1` conserva el comportamiento histórico. Solo una publicación explícita del perfil general con `duration-first-v2` activa la arquitectura basada primero en duración.
- La activación comienza en el primer período de nómina configurado que todavía no haya comenzado. Reasigna prospectivamente a los empleados actuales y conserva versiones, asignaciones y resultados previos.
- Toda operación de nómina bloquea filas por identificador en el orden `company → payPeriods → profiles → publications → employees → assignments → rawMarks`. El bloqueador de contexto es el único propietario de la transacción de nómina.

### Reconocimiento basado primero en duración

- Los intervalos son semiabiertos `[inicio,fin)`. El tiempo transcurrido se cuantiza una vez a minutos completos.
- De lunes a sábado no feriado, los primeros 480 minutos son ordinarios. Los minutos posteriores se clasifican por hora local: +75 % `[00:00,06:00)`, +25 % `[06:00,18:00)` y +50 % `[18:00,24:00)`.
- En domingo o feriado, todo el tiempo observado es +100 % y no se generan cuota ordinaria, déficit diario, variación de entrada ni otro candidato.
- Una cola posterior a una hora extra completa se excluye solo si contiene de 1 a 30 minutos; con 0 o más de 30 minutos se conserva completa.

### Auditoría, procesamiento e informes

- Déficits, candidatos, decisiones y reconocimientos nuevos se identifican siempre con huellas canónicas que incluyen publicación, asignación, jornada, calendario y revisiones de marcas. Una huella obsoleta no agrega registros.
- La lectura histórica admite solo dos compatibilidades verificables: la identidad exacta publicada por la versión anterior que aún no incluía publicación, y la identidad exacta `schedule-overlap-v1` inmediatamente sustituida por una activación `duration-first-v2` en la misma fecha laboral. La segunda se reconstruye con la asignación, publicación y jornada predecesoras, pero con las mismas marcas, revisiones, generaciones y calendario vigentes; además, el hecho anterior y el actual deben conservar íntegramente minutos y bandas. Ninguna coincidencia por forma, clave o huella aislada es suficiente.
- Una nueva decisión puede sustituir una raíz histórica únicamente después de esa verificación; el registro nuevo conserva la identidad canónica vigente y el anterior permanece inmutable. Siempre que la raíz coincida mediante una identidad compatible no canónica —tanto en la transición de formato V1→V1 como en la de política V1→V2— la aplicación entrega a PostgreSQL una autorización transaccional de un solo uso vinculada al tipo de decisión, raíz, versión e identidad canónica hija, empresa, período, empleado y fecha exactos. El trigger la consume y rechaza inserciones directas basadas solo en la forma; el historial V1 que conserva exactamente la misma identidad canónica continúa sin esa excepción. Identidades de otra empresa, período, empleado, fecha, evidencia o transición no pueden leerse ni originar sustituciones.
- Un déficit pendiente o un candidato sin resolver bloquea el procesamiento. Las decisiones y reconocimientos son anexos auditados: no actualizan ni eliminan hechos anteriores.
- El procesamiento inserta una instantánea diaria inmutable y versionada. Un reintento idéntico puede reutilizarla; un reintento conflictivo falla sin reescribirla.
- Los informes actuales leen la instantánea, no recalculan asistencia. Las filas `LEGACY` conservan lo conocido y dejan nulo lo desconocido.
- Subtotales y totales suman minutos enteros antes de convertir a horas decimales para presentación.
