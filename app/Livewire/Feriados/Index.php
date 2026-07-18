<?php

namespace App\Livewire\Feriados;

use App\Models\Holiday;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.app')]
class Index extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    public bool $showCreateModal = false;

    public ?int $editingId = null;

    public string $formDate = '';

    public string $formName = '';

    public string $formDescription = '';

    public bool $formIsActive = true;

    public bool $confirmingDelete = false;

    public ?int $deleteId = null;

    public function mount(): void
    {
        $this->authorize('viewAny', Holiday::class);
    }

    public function openCreateModal(): void
    {
        $this->authorize('create', Holiday::class);

        $this->resetForm();
        $this->showCreateModal = true;
    }

    public function closeCreateModal(): void
    {
        $this->showCreateModal = false;
        $this->resetForm();
    }

    public function edit(int $id): void
    {
        $holiday = Holiday::withoutCompanyScope()->find($id);

        if (! $holiday) {
            return;
        }

        $this->authorize('update', $holiday);

        $this->editingId = $id;
        $this->formDate = $holiday->date->format('Y-m-d');
        $this->formName = $holiday->name;
        $this->formDescription = $holiday->description ?? '';
        $this->formIsActive = (bool) $holiday->is_active;
        $this->showCreateModal = true;
    }

    public function save(): void
    {
        $companyId = current_company_id();

        if ($companyId === null) {
            return;
        }

        $validated = $this->validate([
            'formDate' => ['required', 'date'],
            'formName' => ['required', 'string', 'max:150'],
            'formDescription' => ['nullable', 'string'],
            'formIsActive' => ['boolean'],
        ]);

        if ($this->editingId) {
            $holiday = Holiday::withoutCompanyScope()->find($this->editingId);

            if (! $holiday) {
                return;
            }

            $this->authorize('update', $holiday);

            $holiday->update([
                'date' => $validated['formDate'],
                'name' => $validated['formName'],
                'description' => $validated['formDescription'] ?: null,
                'is_active' => $validated['formIsActive'],
            ]);

            $this->dispatch('holiday-saved');
        } else {
            $this->authorize('create', Holiday::class);

            Holiday::withoutCompanyScope()->updateOrCreate(
                [
                    'company_id' => $companyId,
                    'date' => $validated['formDate'],
                ],
                [
                    'name' => $validated['formName'],
                    'description' => $validated['formDescription'] ?: null,
                    'is_active' => $validated['formIsActive'],
                ]
            );
        }

        $this->showCreateModal = false;
        $this->editingId = null;
        $this->resetForm();
    }

    public function confirmDelete(int $id): void
    {
        $this->deleteId = $id;
        $this->confirmingDelete = true;
    }

    public function delete(): void
    {
        $holiday = Holiday::withoutCompanyScope()->find($this->deleteId);

        if (! $holiday) {
            $this->confirmingDelete = false;
            $this->deleteId = null;

            return;
        }

        $this->authorize('delete', $holiday);

        $holiday->delete();

        $this->confirmingDelete = false;
        $this->deleteId = null;
    }

    public function toggle(int $id): void
    {
        $holiday = Holiday::withoutCompanyScope()->find($id);

        if (! $holiday) {
            return;
        }

        $this->authorize('update', $holiday);

        $holiday->update(['is_active' => ! $holiday->is_active]);
    }

    public function resetForm(): void
    {
        $this->editingId = null;
        $this->formDate = '';
        $this->formName = '';
        $this->formDescription = '';
        $this->formIsActive = true;
        $this->resetErrorBag();
    }

    public function render()
    {
        $companyId = current_company_id();

        $holidays = Holiday::query()
            ->when($this->search, function ($query) {
                $search = '%'.$this->search.'%';
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', $search)
                        ->orWhere('description', 'like', $search);
                });
            })
            ->orderBy('date', 'desc')
            ->paginate(15);

        return view('livewire.feriados.index', [
            'holidays' => $holidays,
            'hasCompany' => $companyId !== null,
        ]);
    }
}
