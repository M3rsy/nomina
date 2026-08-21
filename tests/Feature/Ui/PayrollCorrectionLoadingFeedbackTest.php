<?php

test('payroll corrections expose scoped loading feedback for open and save actions', function () {
    $view = file_get_contents(resource_path('views/livewire/nomina/revisar.blade.php'));

    foreach ([
        'openReopenModal',
        'openManualMarkModal(',
        'openEditRawMark(',
        'openAssignModal(',
        'openCreateEmployeeModal(',
        'openCorrectRawMark(',
        'openDeleteRawMark(',
        'saveManualMark',
        'saveEditRawMark',
        'markCorrected',
        'deleteRawMark',
        'saveAssign',
        'saveCreatedEmployee',
        'reopenProcessedPeriod',
    ] as $action) {
        expect($view)->toContain('target="'.$action);
    }
});
