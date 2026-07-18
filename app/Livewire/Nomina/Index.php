<?php

namespace App\Livewire\Nomina;

use App\Models\PayPeriod;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.app')]
class Index extends Component
{
    use WithPagination;

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
}
