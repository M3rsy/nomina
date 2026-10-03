<?php

namespace App\Livewire\Empleados;

use App\Models\Employee;
use App\Models\User;
use App\Services\Employees\EmployeeBulkImportService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

#[Layout('components.layouts.app')]
class Index extends Component
{
    use WithFileUploads;
    use WithPagination;

    public string $search = '';

    public string $filter = 'active';

    public ?TemporaryUploadedFile $importFile = null;

    /** @var list<array{message: string, count: int, rows: list<int>}> */
    public array $importErrors = [];

    public ?array $importSummary = null;

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedFilter(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset('search');
        $this->filter = 'active';
        $this->resetPage();
    }

    public function importEmployees(): void
    {
        $this->authorize('create', Employee::class);
        $this->resetValidation();
        $this->importErrors = [];
        $this->importSummary = null;

        $this->validate([
            'importFile' => ['required', 'file', 'extensions:xlsx', 'max:10240'],
        ], [
            'importFile.required' => 'Seleccioná un archivo Excel.',
            'importFile.extensions' => 'Solo se permiten archivos .xlsx.',
            'importFile.max' => 'El archivo no puede superar los 10 MB.',
        ]);

        /** @var User $user */
        $user = Auth::user();
        $companyId = $user->hasRole('super_admin') ? current_company_id() : $user->company_id;

        if ($companyId === null) {
            $this->addError('importFile', 'No se pudo determinar la empresa activa.');

            return;
        }

        try {
            $result = app(EmployeeBulkImportService::class)->import($this->importFile, $companyId, $user);
            $this->importSummary = $result;
            $this->reset('importFile');
        } catch (ValidationException $exception) {
            $this->importErrors = $this->groupImportErrors($exception->errors());
            $this->addError('importFile', 'No se importó ningún empleado. Corregí los errores indicados.');
        }
    }

    /** @param array<string, list<string>> $errors */
    private function groupImportErrors(array $errors): array
    {
        $grouped = [];

        foreach ($errors as $key => $messages) {
            preg_match('/^rows\\.(\\d+)/', $key, $match);
            $row = isset($match[1]) ? (int) $match[1] : null;

            foreach ($messages as $message) {
                $grouped[$message] ??= ['message' => $message, 'count' => 0, 'rows' => []];
                $grouped[$message]['count']++;
                if ($row !== null && ! in_array($row, $grouped[$message]['rows'], true)) {
                    $grouped[$message]['rows'][] = $row;
                }
            }
        }

        return array_values($grouped);
    }

    #[On('employee-deleted')]
    #[On('employee-restored')]
    #[On('employee-status-changed')]
    public function refreshEmployees(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $this->authorize('viewAny', Employee::class);

        /** @var User $user */
        $user = Auth::user();
        $isSuperAdmin = $user->hasRole('super_admin');

        $employees = Employee::query()
            ->with('company')
            ->when($this->filter === 'retired', function ($query) {
                $query->onlyTrashed();
            })
            ->when($this->filter === 'active', function ($query) {
                $query->where('is_active', true);
            })
            ->when($this->filter === 'inactive', function ($query) {
                $query->where('is_active', false);
            })
            ->when($this->search, function ($query) {
                $search = '%'.$this->search.'%';
                $query->where(function ($q) use ($search) {
                    $q->where('external_id', 'like', $search)
                        ->orWhere('payment_code', 'like', $search)
                        ->orWhere('dni', 'like', $search)
                        ->orWhere('first_name', 'like', $search)
                        ->orWhere('last_name', 'like', $search);
                });
            })
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->orderBy('id')
            ->paginate(10);

        return view('livewire.empleados.index', [
            'employees' => $employees,
            'isSuperAdmin' => $isSuperAdmin,
        ]);
    }
}
