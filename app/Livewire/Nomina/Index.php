<?php

namespace App\Livewire\Nomina;

use App\Models\PayPeriod;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.app')]
class Index extends Component
{
    use WithPagination;

    public string $name = '';

    public string $start_date = '';

    public string $end_date = '';

    public bool $showCreateForm = false;

    public function mount(): void
    {
        $this->authorize('viewAny', PayPeriod::class);
    }

    public function render()
    {
        $company = current_company();

        $payPeriods = $company !== null
            ? PayPeriod::query()->orderBy('start_date', 'desc')->paginate(10)
            : collect();

        return view('livewire.nomina.index', [
            'payPeriods' => $payPeriods,
            'hasCompany' => $company !== null,
        ]);
    }

    public function openCreateForm(): void
    {
        $this->authorize('create', PayPeriod::class);

        $this->showCreateForm = true;
    }

    public function closeCreateForm(): void
    {
        $this->authorize('create', PayPeriod::class);

        $this->reset('name', 'start_date', 'end_date', 'showCreateForm');
        $this->resetValidation();
    }

    public function store(): void
    {
        $this->authorize('create', PayPeriod::class);
        $this->showCreateForm = true;

        $company = current_company();

        abort_if($company === null, 403);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
        ], [
            'name.required' => 'Ingresá un nombre para el período.',
            'name.string' => 'El nombre del período debe ser texto.',
            'name.max' => 'El nombre no puede superar los 120 caracteres.',
            'start_date.required' => 'Ingresá la fecha de inicio.',
            'start_date.date' => 'Ingresá una fecha de inicio válida.',
            'end_date.required' => 'Ingresá la fecha de fin.',
            'end_date.date' => 'Ingresá una fecha de fin válida.',
            'end_date.after_or_equal' => 'La fecha de fin debe ser igual o posterior a la fecha de inicio.',
        ]);

        $slug = Str::slug($validated['name']);

        if (PayPeriod::withTrashed()
            ->where('company_id', $company->id)
            ->where('slug', $slug)
            ->exists()) {
            $this->addError('name', 'Ya existe un período con este nombre en la empresa activa.');

            return;
        }

        try {
            $payPeriod = PayPeriod::create([
                'company_id' => $company->id,
                'slug' => $slug,
                'name' => $validated['name'],
                'start_date' => $validated['start_date'],
                'end_date' => $validated['end_date'],
                'status' => 'draft',
            ]);
        } catch (UniqueConstraintViolationException) {
            $this->addError('name', 'Ya existe un período con este nombre en la empresa activa.');

            return;
        }

        $this->redirectRoute('archivos.upload', [
            'pay_period_id' => $payPeriod->id,
        ], navigate: true);
    }
}
