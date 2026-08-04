# Proposal: Flexible Single-Shift Payroll

## Intent

Replace schedule-overlap payroll with duration-first recognition while preserving attendance evidence, auditability, and history.

## Scope

### In Scope
- Monday–Saturday: first `min(observed, 480)` whole minutes are ordinary; excess is +75% 00:00–06:00, +25% 06:00–18:00, +50% 18:00–24:00. Sunday/holiday: all observed +100%, zero ordinary.
- Quantize once; preserve every `Marca observada` timestamp/revision. Entry after 06:20 when quota is reached emits a nonblocking variation; audited append-only acknowledgement never affects pay.
- Exclude only a ≤30-minute overtime tail after a completed payable-hour boundary; preserve >30 fully.
- One `daily_shortfall = 480 - observed minutes` is a quota deficit, never an interval. Pending blocks payroll; GRANTED pays; REJECTED resolves unpaid; REVOKED only supersedes GRANTED and returns pending.
- Detected overtime is immutable. Authorize all or one exact contiguous subinterval; server-derived rates apply and the complement is rejected. Whole rejection remains; batches remain whole-candidate only.
- Activate the general profile at the first next not-started payroll-period start; assign current employees prospectively and resolve new employees solely by the date-effective general profile.
- Freeze daily snapshots, bump `rules_version`, and export both Excel reports with employee/grand totals from integer-minute sums. Historical rows expose additions as null/legacy-labelled; never recalculate.
- Update `CONTEXT.md` and add a concise superseding ADR.

### Out of Scope
- Mark mutation, retroactive assignment, historical backfill, or partial batch authorization.
- Schema and module signatures; design/spec phases own them.

## Capabilities

### New Capabilities
- `duration-first-attendance-payroll`: recognition rules.
- `audited-payroll-decisions`: shortfall, variation, overtime.
- `effective-dated-general-profile`: activation/assignment.
- `immutable-payroll-reporting`: snapshots/exports.

### Modified Capabilities
None; no baseline specs exist.

## Approach

Deepen analyzer/evaluator seams and append-only recorders; add activation, variation-acknowledgement, and snapshot modules. Deliver nine public-interface RED→GREEN→REFACTOR slices: quota; variation/tail; shortfall; acknowledgement; overtime; profiles; snapshots; exports; docs.

## Affected Areas

| Area | Impact |
|---|---|
| Attendance/schedule/payroll modules | Modified |
| Tests, `CONTEXT.md`, ADRs | Modified |

## Risks

| Risk | Mitigation |
|---|---|
| Shortfall misrepresented | No interval semantics |
| Activation race | Locking plus PostgreSQL tests |
| History loss | Immutable facts; no recalculation |

## Rollback Plan

Stop future activation and new-policy processing; retain additive audit/snapshot data and processed results unchanged.

## Dependencies

- Duplicate search, one approved feature issue, then draft/no-merge Feature Branch Chain tracker and children; target ~400 authored lines (800 maximum), exactly `type:feature` per PR.
- WU5 only has a maintainer-approved `size:exception` in Engram #2203: 809 authored lines, exactly 9 above 800, to preserve the reviewed WU5–WU10 chain. The hard 800-line maximum remains unchanged for every other work unit. This metadata does not complete delivery task `0.1`.

## Success Criteria

- [ ] Public-interface tests cover 06–14, 08–16, 09–17, 12–20, 06–16, 09–19, 12–21, Sunday/holiday, 06:20–14:20, 07–15, 06–16:25/16:31; all decision states, stale fingerprints, profile timing/concurrency/new hires, blockers, immutable processing, legacy/null exports, and totals.
- [ ] Conventional commits, behavior-named tests, no AI attribution, and the content-bound receipt lifecycle remain required. Final acceptance uses one native evidence-classified cumulative bounded review. The current neutral cumulative candidate is expected to select `reliability`; the contract defers to the native selected lens set rather than fabricating risk or claiming all four lenses. Issue #2387 is a future upstream enhancement, not a blocker. Task `5.2` remains unchecked until the cumulative receipt, fixes/follow-ups, final evidence, delivery gates, and SDD binding are complete. One-review-budget and receipt-validation semantics remain unchanged.
