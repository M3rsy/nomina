# Fase 7 — Excel reference analysis

Reference files read-only at `/home/m3rsy/GIt/proyecto-planilla/`:

- `Asistencia desde GLG 20240120 hasta 20240127.xlsx` (42 KB)
- `S4 NOVIEMBRE 2025.xlsx` (42 KB)

## Common layout

- Single sheet (`Hoja1`).
- Header row 1, data starts row 2.
- No merged cells or title rows in the inspected files; the product spec adds a
  title row 2, week label row 3 and header row 5.
- Header columns:
  `Codigo | Entrada | Salida | Cantidad Horas | Horas Ordinarias | Horas Ext 25% | Horas Ext 50% | Horas Ext 75% | Horas Ext 100%`.
  The Nov-2025 file also has `TOTAL HORAS | C/TRABAJO`.
  Fase 7 adds `NOMBRE` after `Codigo` and omits the `CLAVE` column.
- Date/time cells are stored as Excel serials and displayed with a custom format
  equivalent to `YYYY-MM-DD h:mm AM/PM`.
- Hours columns are numeric (decimal for `Cantidad/Ordinarias`, integers for extra bands).
- `S4 NOVIEMBRE 2025.xlsx` inserts a `TOTAL {Codigo}` subtotal row after each
  employee's days. Fase 7 MVP does **not** add per-employee subtotal rows in the
  main report; that behaviour is provided by the per-employee comprobante.

## Column widths (reference)

Reference widths are ~13-21. The exporter uses fixed widths that fit the new
`NOMBRE` column:

| Column | Width |
|--------|------:|
| Codigo | 12 |
| NOMBRE | 30 |
| Entrada | 22 |
| Salida | 22 |
| Cantidad Horas | 16 |
| Horas Ordinarias | 18 |
| Horas Ext 25% | 16 |
| Horas Ext 50% | 16 |
| Horas Ext 75% | 16 |
| Horas Ext 100% | 17 |

## Filename pattern

Main report: `Asistencia {startYmd} hasta {endYmd}.xlsx`
(e.g. `Asistencia 20240120 hasta 20240127.xlsx`).

Per-employee stub: `Comprobante {external_id} {period_slug}.xlsx`
