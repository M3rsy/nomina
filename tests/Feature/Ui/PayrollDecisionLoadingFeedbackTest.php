<?php

test('payroll decisions expose scoped loading feedback without targeting selection actions', function () {
    $view = file_get_contents(resource_path('views/livewire/nomina/revisar.blade.php'));

    expect($view)
        ->toContain('target="acknowledgeVariation(')
        ->toContain('target="openOvertimeDecision(')
        ->toContain('target="openOvertimeBatch(')
        ->toContain('target="openAttendanceException(')
        ->toContain('target="saveAttendanceException"')
        ->toContain('target="saveOvertimeBatch"')
        ->toContain('target="saveOvertimeDecision"')
        ->not->toContain('target="selectCurrentOvertimePage"')
        ->not->toContain('target="selectAllFilteredOvertime"')
        ->not->toContain('target="clearOvertimeSelection"');
});
