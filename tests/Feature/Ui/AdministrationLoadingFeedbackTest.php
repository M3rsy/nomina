<?php

test('administration commands use the shared action-scoped loading button', function () {
    $contracts = [
        'auth/login.blade.php' => ['login'],
        'auth/forgot-password.blade.php' => ['sendResetLink'],
        'auth/reset-password.blade.php' => ['resetPassword'],
        'auth/logout.blade.php' => ['logout'],
        'profile/change-password.blade.php' => ['save'],
        'empleados/create.blade.php' => ['save'],
        'empleados/edit.blade.php' => ['save'],
        'empleados/delete.blade.php' => ['destroy'],
        'empleados/toggle-activate.blade.php' => ['toggle'],
        'usuarios/create.blade.php' => ['save'],
        'usuarios/edit.blade.php' => ['save'],
        'empresas/create.blade.php' => ['save'],
        'empresas/edit.blade.php' => ['save'],
        'empresas/index.blade.php' => ['toggle(', 'delete('],
    ];

    foreach ($contracts as $path => $actions) {
        $view = file_get_contents(resource_path('views/livewire/'.$path));

        expect($view)->toContain('<x-ui.loading-button');

        foreach ($actions as $action) {
            expect($view)->toContain('target="'.$action);
        }
    }
});
