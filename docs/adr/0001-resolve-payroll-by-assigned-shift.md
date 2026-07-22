# Resolver la nomina desde la jornada asignada

La nomina se resolvera por la jornada vigente de cada empleado y su fecha laboral, no por la fecha calendario de cada marca ni por un horario unico de empresa. El tiempo programado se clasificara automaticamente por las bandas salariales; solo los tramos cubiertos por marcas de asistencia fuera de la jornada seran candidatos que una persona autorizada debe aprobar o rechazar completos y con motivo. Esta separacion conserva el reloj como evidencia, permite turnos nocturnos y evita pagar automaticamente demoras de traslado u otros excesos no autorizados.

## Consecuencias

- Las jornadas usadas deben conservar su version historica.
- Un turno que cruza medianoche pertenece a la fecha en que comienza.
- Una unica marca a cada lado del corte con un dia no laborable completa el par nocturno; marcas adicionales conservan la separacion o la ambiguedad para revision.
- Las decisiones se registran en minutos exactos y no aceptan duraciones ingresadas por el usuario.
- Corregir una marca o cambiar una jornada deja obsoletas las decisiones calculadas con los datos anteriores.
- Los deficit justificados se reconocen mediante excepciones de asistencia, nunca falsificando la marca observada.
- Una entrada o salida real omitida por el reloj puede incorporarse como marca manual auditada, con actor, motivo e instante. No crea archivo, fila ni `raw_line`, y no permite ingresar tiempo pagable.
