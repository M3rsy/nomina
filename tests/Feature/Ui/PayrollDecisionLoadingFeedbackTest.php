<?php

test('payroll decisions expose scoped loading feedback without targeting selection actions', function () {
    $review = file_get_contents(resource_path('views/livewire/nomina/revisar.blade.php'));
    $panel = file_get_contents(resource_path('views/livewire/nomina/overtime-review-panel.blade.php'));

    expect($review)
        ->toContain('target="acknowledgeVariation(')
        ->toContain('target="openAttendanceException(')
        ->toContain('target="saveAttendanceException"')
        ->not->toContain('target="openOvertimeDecision(')
        ->not->toContain('target="openOvertimeBatch(')
        ->not->toContain('target="saveOvertimeBatch"')
        ->not->toContain('target="saveOvertimeDecision"');

    expect($panel)
        ->toContain('wire:click="openOvertimeDecision(')
        ->toContain('wire:click="openOvertimeBatch(')
        ->toContain('target="submitOvertimeBatch"')
        ->toContain('target="submitOvertimeDecision"')
        ->not->toContain('target="selectCurrentOvertimePage"')
        ->not->toContain('target="selectAllFilteredOvertime"')
        ->not->toContain('target="clearOvertimeSelection"');
});
