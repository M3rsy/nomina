<?php

namespace App\Livewire\Archivos;

use App\Models\UploadedFile;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.app')]
class Show extends Component
{
    use WithPagination;

    public UploadedFile $uploadedFile;

    public function mount(UploadedFile $uploadedFile): void
    {
        $this->authorize('view', $uploadedFile);
        $this->uploadedFile = $uploadedFile;
    }

    public function render()
    {
        $records = $this->uploadedFile->rawMarks()
            ->orderBy('row_number')
            ->paginate(25);

        $counts = $this->uploadedFile->rawMarks()
            ->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        $summary = $this->uploadedFile->validation_summary ?? [];

        return view('livewire.archivos.show', [
            'records' => $records,
            'counts' => $counts,
            'summary' => $summary,
        ]);
    }
}
