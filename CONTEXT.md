# Asistencia y nomina

Este contexto transforma marcas de asistencia en tiempo reconocido para nomina sin alterar los hechos registrados por el reloj.

## Lenguaje

**Marca de asistencia**:
Registro normalizado de una entrada o salida usado para resolver una jornada. Puede provenir del reloj o de un hecho manual auditado.
_Evitar_: Hora trabajada, hora pagable

**Marca observada**:
Registro real de entrada o salida producido por el reloj de asistencia.
_Evitar_: Marca manual, hora trabajada

**Marca manual auditada**:
Registro normalizado de una entrada o salida real que el reloj omitio, incorporado con actor, motivo e instante de auditoria sin crear evidencia de archivo.
_Evitar_: Marca observada, ajuste de horas, excepcion de asistencia

**Jornada asignada**:
Horario laboral vigente para un empleado en una fecha determinada.
_Evitar_: Jornada de empresa, horario general

**Fecha laboral**:
Fecha en la que comienza una jornada, incluso cuando termina al dia siguiente.
_Evitar_: Fecha calendario de salida

**Tiempo programado**:
Parte de la jornada asignada que se reconoce automaticamente segun las bandas salariales aplicables.
_Evitar_: Hora ordinaria, cuando el tramo pertenece a una banda adicional

**Deficit de asistencia**:
Tramo programado que no esta cubierto por las marcas de asistencia vigentes.
_Evitar_: Ausencia, cuando solo falta una parte de la jornada

**Excepcion de asistencia**:
Decision auditada que reconoce un deficit de asistencia sin crear ni modificar una marca de asistencia.
_Evitar_: Correccion de marca, permiso informal

**Candidato de hora extra**:
Tramo cubierto por marcas de asistencia fuera de la jornada asignada que todavia no genera tiempo pagable.
_Evitar_: Hora extra, hora extra aprobada

**Autorizacion de hora extra**:
Decision auditada que aprueba o rechaza un candidato completo.
_Evitar_: Ajuste manual de horas, aprobacion parcial

**Tiempo pagable**:
Tiempo finalmente reconocido para nomina, expresado en minutos exactos y clasificado por banda salarial.
_Evitar_: Tiempo observado
