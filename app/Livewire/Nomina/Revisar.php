<?php

namespace App\Livewire\Nomina;

use App\Models\PayPeriod;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class Revisar extends Component
{
    public PayPeriod $payPeriod;

    public function mount(PayPeriod $payPeriod): void
    {
        $this->payPeriod = $payPeriod;
    }

    public function render()
    {
        return view('livewire.nomina.revisar');
    }
}
