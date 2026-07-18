<?php

namespace App\Livewire\Archivos;

use App\Models\UploadedFile;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.app')]
class Index extends Component
{
    use WithPagination;

    public string $search = '';

    public string $status = '';

    public ?int $pay_period_id = null;

    public function render()
    {
        $this->authorize('viewAny', UploadedFile::class);

        $files = UploadedFile::query()
            ->with('payPeriod')
            ->when($this->search, function ($query) {
                $query->where('original_name', 'like', '%'.$this->search.'%');
            })
            ->when($this->status, function ($query) {
                $query->where('status', $this->status);
            })
            ->when($this->pay_period_id, function ($query) {
                $query->where('pay_period_id', $this->pay_period_id);
            })
            ->orderBy('created_at', 'desc')
            ->paginate(10);

        return view('livewire.archivos.index', [
            'files' => $files,
        ]);
    }
}
