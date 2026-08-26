# Overtime Review Panel Chain

This feature chain isolates the overtime review panel without changing payroll
decision semantics.

```text
main
└── perf/overtime-review-panel (tracker)
    └── perf/overtime-review-panel-state (filters, pagination, selection)
        └── perf/overtime-review-panel-actions (decision UI and commands)
            └── perf/overtime-review-panel-integration (replace parent state)
```

## Work units

1. Establish panel-owned filters, pagination, and selection state.
2. Move overtime UI, modals, and decision actions while retaining the audited
   command services and a minimal readiness notification to `Revisar`.
3. Replace the parent overtime surface and prove the state has one owner.

Lazy loading, deficits, and variations are out of scope for this chain.
