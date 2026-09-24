<?php

namespace App\Support\Nomina;

final readonly class PayPeriodStatusPresentation
{
    /**
     * @param  'neutral'|'brand'|'success'|'warning'|'danger'  $badgeVariant
     */
    private function __construct(
        public string $status,
        public bool $known,
        public string $label,
        public string $badgeVariant,
        public ?string $phaseKey,
        public ?int $phaseIndex,
        public string $copy,
    ) {}

    public static function for(string $status): self
    {
        $metadata = self::metadata()[$status] ?? null;

        if ($metadata === null) {
            return new self(
                status: $status,
                known: false,
                label: 'Estado desconocido',
                badgeVariant: 'neutral',
                phaseKey: null,
                phaseIndex: null,
                copy: 'El estado almacenado no se reconoce; no se habilitan acciones a partir de esta presentación.',
            );
        }

        return new self($status, true, ...$metadata);
    }

    /**
     * @return list<array{key: string, index: int, label: string}>
     */
    public static function phases(): array
    {
        return [
            ['key' => 'period', 'index' => 1, 'label' => 'Período'],
            ['key' => 'upload', 'index' => 2, 'label' => 'Carga'],
            ['key' => 'review', 'index' => 3, 'label' => 'Revisión'],
            ['key' => 'process', 'index' => 4, 'label' => 'Proceso'],
            ['key' => 'finalize', 'index' => 5, 'label' => 'Aprobación y exportación'],
        ];
    }

    /**
     * @return array<string, array{label: string, badgeVariant: string, phaseKey: string, phaseIndex: int, copy: string}>
     */
    private static function metadata(): array
    {
        return [
            'draft' => [
                'label' => 'Borrador',
                'badgeVariant' => 'neutral',
                'phaseKey' => 'period',
                'phaseIndex' => 1,
                'copy' => 'El período fue creado y puede recibir archivos de asistencia.',
            ],
            'uploaded' => [
                'label' => 'Archivo cargado',
                'badgeVariant' => 'brand',
                'phaseKey' => 'upload',
                'phaseIndex' => 2,
                'copy' => 'Hay un archivo cargado y se permite cargar otro; esto no confirma la validación.',
            ],
            'validating' => [
                'label' => 'Validando',
                'badgeVariant' => 'warning',
                'phaseKey' => 'review',
                'phaseIndex' => 3,
                'copy' => 'La asistencia está en revisión y deben resolverse los bloqueos visibles.',
            ],
            'validation_failed' => [
                'label' => 'Validación con errores',
                'badgeVariant' => 'danger',
                'phaseKey' => 'upload',
                'phaseIndex' => 2,
                'copy' => 'La validación falló; corrija el archivo y vuelva a cargarlo.',
            ],
            'ready' => [
                'label' => 'Listo',
                'badgeVariant' => 'success',
                'phaseKey' => 'review',
                'phaseIndex' => 3,
                'copy' => 'La revisión está lista y el procesamiento puede solicitarse si no hay bloqueos.',
            ],
            'processing' => [
                'label' => 'Procesando',
                'badgeVariant' => 'brand',
                'phaseKey' => 'process',
                'phaseIndex' => 4,
                'copy' => 'El cálculo está activo y la asistencia no puede editarse.',
            ],
            'processed' => [
                'label' => 'Procesado',
                'badgeVariant' => 'success',
                'phaseKey' => 'finalize',
                'phaseIndex' => 5,
                'copy' => 'Los resultados actuales están congelados y disponibles para revisión y aprobación.',
            ],
            'approved' => [
                'label' => 'Aprobado',
                'badgeVariant' => 'success',
                'phaseKey' => 'finalize',
                'phaseIndex' => 5,
                'copy' => 'La nómina está aprobada, bloqueada y disponible para exportar.',
            ],
            'exported' => [
                'label' => 'Exportado',
                'badgeVariant' => 'neutral',
                'phaseKey' => 'finalize',
                'phaseIndex' => 5,
                'copy' => 'La nómina fue exportada, permanece bloqueada y puede exportarse nuevamente.',
            ],
            'cancelled' => [
                'label' => 'Cancelado',
                'badgeVariant' => 'danger',
                'phaseKey' => 'finalize',
                'phaseIndex' => 5,
                'copy' => 'El período está cancelado y no admite edición.',
            ],
        ];
    }
}
