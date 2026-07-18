<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class EmployeeController extends Controller
{
    public function destroy(Request $request, Employee $employee): RedirectResponse
    {
        Gate::authorize('delete', $employee);

        if ($employee->trashed()) {
            abort(422, 'El empleado ya está desactivado.');
        }

        $employee->delete();

        return redirect()->route('empleados.index')->with('success', 'Empleado eliminado.');
    }

    public function activate(Request $request, Employee $employee): RedirectResponse
    {
        Gate::authorize('activate', $employee);

        $employee->update(['is_active' => true]);

        return redirect()->route('empleados.index')->with('success', 'Empleado activado.');
    }

    public function deactivate(Request $request, Employee $employee): RedirectResponse
    {
        Gate::authorize('activate', $employee);

        $employee->update(['is_active' => false]);

        return redirect()->route('empleados.index')->with('success', 'Empleado desactivado.');
    }
}
