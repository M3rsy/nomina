# Payroll Workflow Presentation Specification

## Purpose

Define a presentation-only, permission-correct view of pay-period status and period management without changing payroll state, authorization, tenancy, or audit behavior.

## Requirements

### Requirement: Exact pay-period presentation mapping

The system MUST map every stored pay-period status to the exact Spanish label, semantic badge tone, five-phase position, and explanatory copy below. This mapping MUST be presentation-only and MUST NOT authorize actions, perform transitions, infer a different status, or persist a visual phase.

| Stored status | Exact label | Badge tone | Visual phase | Explanatory copy |
| --- | --- | --- | --- | --- |
| `draft` | Borrador | neutral | Período | El período fue creado y puede recibir archivos de asistencia. |
| `uploaded` | Archivo cargado | brand | Carga | Hay un archivo cargado y se permite cargar otro; esto no confirma la validación. |
| `validating` | Validando | warning | Revisión | La asistencia está en revisión y deben resolverse los bloqueos visibles. |
| `validation_failed` | Validación con errores | danger | Carga | La validación falló; corrija el archivo y vuelva a cargarlo. |
| `ready` | Listo | success | Revisión | La revisión está lista y el procesamiento puede solicitarse si no hay bloqueos. |
| `processing` | Procesando | brand | Proceso | El cálculo está activo y la asistencia no puede editarse. |
| `processed` | Procesado | success | Aprobar y exportar | Los resultados actuales están congelados y disponibles para revisión y aprobación. |
| `approved` | Aprobado | success | Aprobar y exportar | La nómina está aprobada, bloqueada y disponible para exportar. |
| `exported` | Exportado | neutral | Aprobar y exportar | La nómina fue exportada, permanece bloqueada y puede exportarse nuevamente. |
| `cancelled` | Cancelado | danger | Aprobar y exportar | El período está cancelado y no admite edición. |

#### Scenario: Render every known status

- GIVEN a tenant-visible period in each of the ten stored statuses
- WHEN its status presentation is rendered
- THEN each period shows the exact label, semantic badge tone, visual phase, and explanatory copy specified in the mapping
- AND no visual phase is stored or treated as evidence that a domain transition occurred

#### Scenario: Render an unknown status safely

- GIVEN a tenant-visible period whose stored status is not in the presentation mapping
- WHEN its status presentation is rendered
- THEN the status is explicitly identified as unknown
- AND it is not silently presented as pending or mapped to a known status
- AND the fallback presentation grants no action

### Requirement: Exact lifecycle semantics and transitions

The presentation MUST preserve the ten stored statuses and the existing lifecycle: creation to `draft`; review save or reopen to `validating`; readiness or start checks to `ready`; processing through `processing` to `processed`; approval to `approved`; first approved export to `exported`; and processed reopening to `validating` while prior result generations remain preserved. Audited soft deletion MUST remain separate from `cancelled`. The system MUST NOT add, remove, collapse, reorder, rename, or infer statuses.

#### Scenario: Visual navigation does not mutate lifecycle

- GIVEN a period is displayed in the five-phase workflow
- WHEN an operator views or navigates the presentation
- THEN the stored status remains unchanged
- AND only existing domain actions can perform an existing transition

#### Scenario: Separate status concepts remain separate

- GIVEN pay-period, payroll-run, and overtime-batch statuses are visible together
- WHEN the UI presents their state
- THEN it distinguishes the three status concepts
- AND it does not derive one concept's status from another

### Requirement: Permission-correct period actions

The overview MUST derive action visibility and availability from existing policies, gates, model methods, tenant context, and route constraints rather than from presentation metadata. Upload MUST be available only when the period is `draft`, `uploaded`, or `validation_failed` and the actor has `files.upload`. Review links MUST require `marks.manage`. Create and delete controls MUST follow their existing policies. Direct actions and routes MUST continue to enforce authorization independently of whether a control is rendered.

#### Scenario: Authorized actions retain navigation contracts

- GIVEN an actor has the required permission, active-company context, and a period satisfying the existing action constraints
- WHEN the overview renders an upload or review action
- THEN the action retains its existing route parameters and `wire:navigate` behavior where currently applicable

#### Scenario: Upload matrix is exact

- GIVEN otherwise equivalent periods in all ten statuses and an actor with `files.upload`
- WHEN upload actions are rendered
- THEN upload is available for `draft`, `uploaded`, and `validation_failed`
- AND upload is unavailable for `validating`, `ready`, `processing`, `processed`, `approved`, `exported`, and `cancelled`

#### Scenario: Missing permission hides an action

- GIVEN an actor lacks `files.upload` or `marks.manage`
- WHEN the period overview renders
- THEN the corresponding upload or review action is not offered
- AND direct route authorization remains enforced

### Requirement: Tenant context remains authoritative

All period presentation and actions MUST remain scoped to the active company, including super-admin company context. The UI MUST NOT expose or act on a period, run, batch, or identifier from another tenant. When no active company is available, the page MUST provide guidance rather than presenting unusable tenant actions.

#### Scenario: No active company

- GIVEN an actor who must select an active company has no company context
- WHEN the period overview renders
- THEN the page explains how to establish company context
- AND it does not offer tenant-bound creation or mutation actions

#### Scenario: Forged cross-tenant identifier

- GIVEN an actor submits an identifier belonging to a different company
- WHEN an existing direct action handles the request
- THEN the request is rejected under the existing tenant and authorization rules
- AND presentation metadata does not bypass those rules

### Requirement: Protected period creation and deletion presentation

The index MUST preserve tenant-derived `draft` creation, overlap and slug validation, successful redirect to upload, existing deletion eligibility, required deletion reason, associated-file soft deletion, and audit metadata. The deletion confirmation MUST explain its irreversible consequences and audit effect and MUST be an accessible dialog with an accessible name and description, a labeled reason field, safe initial focus, Escape cancellation, focus return, and busy/disabled duplicate-submission protection.

#### Scenario: Creation remains domain-controlled

- GIVEN an authorized actor with active-company context enters a valid, non-overlapping period
- WHEN creation succeeds
- THEN the period is created for the active company in `draft`
- AND the actor is redirected through the existing upload route contract
- AND no client-controlled company or status value is accepted

#### Scenario: Delete cancellation makes no change

- GIVEN an actor opens the deletion confirmation for a deletable period
- WHEN the actor cancels with Escape or the cancel control
- THEN no period or associated file is deleted
- AND focus returns to the control that opened the dialog

#### Scenario: Confirmed deletion preserves protections

- GIVEN an authorized actor confirms deletion with the required reason
- WHEN deletion is submitted
- THEN the existing soft-deletion and audit behavior runs once
- AND the dialog exposes a busy or disabled state while submission is pending

### Requirement: Accessible responsive overview and empty state

The overview MUST use the existing semantic design-system vocabulary and MUST retain period identity, dates, status, explanation, blockers, and permitted actions across viewport sizes. Larger viewports SHOULD remain scan-friendly; smaller viewports MUST use stacked content or a clearly labeled horizontal-scroll region. When no periods exist, the page MUST explain the empty state and MUST offer period creation only when permitted.

#### Scenario: Empty period list

- GIVEN the active company has no visible pay periods
- WHEN the overview renders
- THEN it explains that no periods exist
- AND an authorized actor receives a clear creation action
- AND an unauthorized actor receives no misleading action

#### Scenario: Small viewport presentation

- GIVEN periods and actions are displayed on a narrow viewport
- WHEN the responsive layout applies
- THEN status context and primary permitted actions remain discoverable
- AND controls can wrap or stack without relying on color alone or hiding blockers
