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
        ->toMatch('/<section(?=[^>]*x-data\s)(?=[^>]*x-on:overtime-batch-accepted="\$dispatch\(\'overtime-batch-modal-close\'\)")(?=[^>]*class="mt-6 rounded-2xl)[^>]*>/')
        ->toContain('wire:click="openOvertimeDecision(')
        ->toContain('wire:click="openOvertimeBatch(\'approved\')" loading-label="Abriendo…" target="openOvertimeBatch(\'approved\')"')
        ->toContain('wire:click="openOvertimeBatch(\'rejected\')" loading-label="Abriendo…" target="openOvertimeBatch(\'rejected\')"')
        ->toContain('target="submitOvertimeBatch"')
        ->toContain('target="submitOvertimeDecision"')
        ->toMatch('/<button(?=[^>]*wire:click="closeOvertimeBatchModal")(?=[^>]*wire:loading\.attr="disabled")(?=[^>]*wire:target="submitOvertimeBatch")[^>]*>/')
        ->toMatch('/<div(?=[^>]*x-data="\{ open: true \}")(?=[^>]*x-show="open")(?=[^>]*x-on:overtime-batch-modal-close\.window="open = false")(?=[^>]*class="fixed inset-0 z-50)[^>]*>/')
        ->not->toContain('x-on:overtime-batch-accepted.window')
        ->not->toContain('x-on:overtime-batch-rejected')
        ->not->toContain('target="openOvertimeBatch"')
        ->not->toContain('target="closeOvertimeBatchModal"')
        ->not->toContain('target="selectCurrentOvertimePage"')
        ->not->toContain('target="selectAllFilteredOvertime"')
        ->not->toContain('target="clearOvertimeSelection"');
});
