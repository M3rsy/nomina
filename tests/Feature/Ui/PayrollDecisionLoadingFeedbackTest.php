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

    expect($review)
        ->toMatch(
            '/<livewire:nomina\.overtime-review-panel(?=[^>]*:pay-period="\$payPeriod")(?=[^>]*:uploaded-file-id="\$uploaded_file_id")(?=[^>]*:is-blocked="\$isBlocked")(?=[^>]*lazy="on-load")[^>]*\/>/s',
        )
        ->toMatch(
            '/<livewire:nomina\.overtime-batch-progress\s+:pay-period="\$payPeriod"\s+:batch-id="\$activeOvertimeBatchId"\s+:key="\'overtime-batch-progress-\'\.\$payPeriod->id"\s*\/>/s',
        )
        ->not->toContain('@if ($activeOvertimeBatchId)');

    expect($panel)
        ->toContain('wire:click="openOvertimeDecision(')
        ->toContain('wire:click="openOvertimeBatch(')
        ->toContain('target="submitOvertimeBatch"')
        ->toContain('target="submitOvertimeDecision"')
        ->not->toContain('target="selectCurrentOvertimePage"')
        ->not->toContain('target="selectAllFilteredOvertime"')
        ->not->toContain('target="clearOvertimeSelection"');
});
