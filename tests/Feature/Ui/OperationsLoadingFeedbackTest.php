<?php

test('operational commands expose scoped loading feedback while passive controls stay quiet', function () {
    $contracts = [
        'nomina/index.blade.php' => ['store'],
        'nomina/procesar.blade.php' => ['approve'],
        'respaldos/index.blade.php' => ['generate', 'confirmRestore(', 'restore'],
        'jornadas/index.blade.php' => ['confirmHistoricalSave', 'openCreateProfile', 'openRetireProfile(', 'createProfile', 'retireProfile', 'activateGeneralProfile', 'save'],
        'feriados/index.blade.php' => ['toggle(', 'edit(', 'confirmDelete(', 'save', 'delete'],
    ];

    foreach ($contracts as $path => $actions) {
        $view = file_get_contents(resource_path('views/livewire/'.$path));

        expect($view)->toContain('<x-ui.loading-button');
        foreach ($actions as $action) {
            expect($view)->toContain('target="'.$action);
        }
    }

    $schedules = file_get_contents(resource_path('views/livewire/jornadas/index.blade.php'));
    expect($schedules)
        ->not->toContain('target="cancelHistoricalSave"')
        ->not->toContain('target="$toggle');
});
