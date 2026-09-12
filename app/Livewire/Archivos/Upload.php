<?php

namespace App\Livewire\Archivos;

use App\Models\Company;
use App\Models\PayPeriod;
use App\Models\UploadedFile;
use App\Models\User;
use App\Services\CurrentCompany;
use App\Services\Parsers\UnsupportedFileException;
use App\Services\UploadedAttendanceFileIngestor;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

#[Layout('components.layouts.app')]
class Upload extends Component
{
    use WithFileUploads;

    public const MAX_SIZE_BYTES = 5 * 1024 * 1024;

    public ?int $pay_period_id = null;

    public $upload;

    public function mount(): void
    {
        $this->authorize('create', UploadedFile::class);

        $company = $this->resolveCompany(auth()->user());
        $queryValue = request()->query('pay_period_id');

        if ($company === null || ! is_scalar($queryValue)) {
            return;
        }

        $payPeriodId = filter_var($queryValue, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);

        if ($payPeriodId === false) {
            return;
        }

        $payPeriod = PayPeriod::query()
            ->where('company_id', $company->id)
            ->uploadable()
            ->find($payPeriodId);

        $this->pay_period_id = $payPeriod?->id;
    }

    public function store(): void
    {
        $this->authorize('create', UploadedFile::class);

        $user = auth()->user();
        $company = $this->resolveCompany($user);

        if ($company === null) {
            $this->addError('pay_period_id', 'No se pudo determinar la empresa activa.');

            return;
        }

        $this->validate([
            'pay_period_id' => ['required', 'integer', 'exists:pay_periods,id'],
            'upload' => [
                'required',
                'file',
                'extensions:txt,dat',
                'max:'.(self::MAX_SIZE_BYTES / 1024),
            ],
        ], [
            'pay_period_id.required' => 'Debe seleccionar un período de nómina.',
            'upload.required' => 'Debe seleccionar un archivo.',
            'upload.extensions' => 'Solo se permiten archivos .txt o .dat.',
            'upload.max' => 'El archivo no puede superar los 5 MB.',
        ]);

        $payPeriod = PayPeriod::find($this->pay_period_id);
        if ($payPeriod === null || $payPeriod->company_id !== $company->id) {
            $this->addError('pay_period_id', 'El período seleccionado no pertenece a la empresa activa.');

            return;
        }

        if (! $payPeriod->canUploadFiles()) {
            $this->addError('pay_period_id', 'El período seleccionado no acepta archivos en este estado.');

            return;
        }

        try {
            $uploadedFile = app(UploadedAttendanceFileIngestor::class)->ingest(
                $company,
                $payPeriod,
                $user,
                $this->upload,
            );
        } catch (UnsupportedFileException) {
            $this->addError('upload', 'El archivo seleccionado no corresponde a un formato de asistencia soportado.');

            return;
        }

        $this->redirect('/archivos/'.$uploadedFile->id, navigate: true);
    }

    public function render()
    {
        $user = auth()->user();
        $company = $this->resolveCompany($user);

        $payPeriods = $company
            ? PayPeriod::where('company_id', $company->id)
                ->uploadable()
                ->orderBy('start_date', 'desc')
                ->get()
            : collect();

        return view('livewire.archivos.upload', [
            'payPeriods' => $payPeriods,
        ]);
    }

    private function resolveCompany(User $user): ?Company
    {
        if ($user->hasRole('super_admin')) {
            return app(CurrentCompany::class)->get();
        }

        return $user->company;
    }
}
