<?php

use App\Http\Controllers\EmployeeController;
use App\Livewire\Auth\ForgotPassword;
use App\Livewire\Auth\Login;
use App\Livewire\Auth\ResetPassword;
use App\Livewire\Empleados\Create as EmployeeCreate;
use App\Livewire\Empleados\Edit as EmployeeEdit;
use App\Livewire\Empleados\Index as EmployeesIndex;
use App\Livewire\Empresas\Create as CompanyCreate;
use App\Livewire\Empresas\Edit as CompanyEdit;
use App\Livewire\Empresas\Index as CompaniesIndex;
use App\Livewire\Profile\ChangePassword;
use App\Livewire\Usuarios\Create as UserCreate;
use App\Livewire\Usuarios\Edit as UserEdit;
use App\Livewire\Usuarios\Index as UsersIndex;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/login', Login::class)
    ->name('login')
    ->middleware('guest');

Route::get('/recuperar', ForgotPassword::class)
    ->name('password.request')
    ->middleware('guest');

Route::get('/reset-password/{token}', ResetPassword::class)
    ->name('password.reset')
    ->middleware('guest');

Route::middleware(['auth'])->group(function () {
    Route::get('/dashboard', function () {
        $user = auth()->user();

        if ($user->hasRole('super_admin')) {
            return redirect('/empresas');
        }

        if ($user->hasRole('company_admin')) {
            return redirect('/usuarios');
        }

        return view('dashboard.index');
    })->name('dashboard');

    Route::get('/perfil/cambiar-contrasena', ChangePassword::class)
        ->name('profile.change-password');
});

Route::middleware(['auth', 'set-active-company', 'can:companies.view'])
    ->prefix('empresas')
    ->group(function () {
        Route::get('/', CompaniesIndex::class)->name('empresas.index');
        Route::get('/crear', CompanyCreate::class)->name('empresas.create');
        Route::get('/{company}/editar', CompanyEdit::class)->name('empresas.edit');
    });

Route::middleware(['auth', 'can:users.view'])
    ->prefix('usuarios')
    ->group(function () {
        Route::get('/', UsersIndex::class)->name('usuarios.index');
        Route::get('/crear', UserCreate::class)->name('usuarios.create');
        Route::get('/{user}/editar', UserEdit::class)->name('usuarios.edit');
    });

Route::middleware(['auth', 'set-active-company', 'can:employees.view'])
    ->prefix('empleados')
    ->group(function () {
        Route::get('/', EmployeesIndex::class)->name('empleados.index');
        Route::get('/crear', EmployeeCreate::class)->name('empleados.create')->can('employees.create');
        Route::get('/{employee}/editar', EmployeeEdit::class)->name('empleados.edit')->can('employees.update');
        Route::delete('/{employee}', [EmployeeController::class, 'destroy'])->name('empleados.destroy')->can('employees.delete');
        Route::post('/{employee}/activar', [EmployeeController::class, 'activate'])->name('empleados.activate')->can('employees.activate');
        Route::post('/{employee}/desactivar', [EmployeeController::class, 'deactivate'])->name('empleados.deactivate')->can('employees.activate');
    });
