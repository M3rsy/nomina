<?php

use App\Support\Nomina\PayPeriodStatusPresentation;

test('pay period statuses expose only their canonical presentation metadata', function (
    string $status,
    string $label,
    string $badgeVariant,
    string $phaseKey,
    int $phaseIndex,
    string $copy,
) {
    $presentation = PayPeriodStatusPresentation::for($status);

    expect($presentation->status)->toBe($status)
        ->and($presentation->known)->toBeTrue()
        ->and($presentation->label)->toBe($label)
        ->and($presentation->badgeVariant)->toBe($badgeVariant)
        ->and($presentation->phaseKey)->toBe($phaseKey)
        ->and($presentation->phaseIndex)->toBe($phaseIndex)
        ->and($presentation->copy)->toBe($copy);
})->with([
    'draft' => ['draft', 'Borrador', 'neutral', 'period', 1, 'El período fue creado y puede recibir archivos de asistencia.'],
    'uploaded' => ['uploaded', 'Archivo cargado', 'brand', 'upload', 2, 'Hay un archivo cargado y se permite cargar otro; esto no confirma la validación.'],
    'validating' => ['validating', 'Validando', 'warning', 'review', 3, 'La asistencia está en revisión y deben resolverse los bloqueos visibles.'],
    'validation failed' => ['validation_failed', 'Validación con errores', 'danger', 'upload', 2, 'La validación falló; corrija el archivo y vuelva a cargarlo.'],
    'ready' => ['ready', 'Listo', 'success', 'review', 3, 'La revisión está lista y el procesamiento puede solicitarse si no hay bloqueos.'],
    'processing' => ['processing', 'Procesando', 'brand', 'process', 4, 'El cálculo está activo y la asistencia no puede editarse.'],
    'processed' => ['processed', 'Procesado', 'success', 'finalize', 5, 'Los resultados actuales están congelados y disponibles para revisión y aprobación.'],
    'approved' => ['approved', 'Aprobado', 'success', 'finalize', 5, 'La nómina está aprobada, bloqueada y disponible para exportar.'],
    'exported' => ['exported', 'Exportado', 'neutral', 'finalize', 5, 'La nómina fue exportada, permanece bloqueada y puede exportarse nuevamente.'],
    'cancelled' => ['cancelled', 'Cancelado', 'danger', 'finalize', 5, 'El período está cancelado y no admite edición.'],
]);

test('unknown pay period statuses remain explicit and inactive', function () {
    $presentation = PayPeriodStatusPresentation::for('future_state');

    expect($presentation->status)->toBe('future_state')
        ->and($presentation->known)->toBeFalse()
        ->and($presentation->label)->toBe('Estado desconocido')
        ->and($presentation->badgeVariant)->toBe('neutral')
        ->and($presentation->phaseKey)->toBeNull()
        ->and($presentation->phaseIndex)->toBeNull()
        ->and($presentation->copy)->toBe('El estado almacenado no se reconoce; no se habilitan acciones a partir de esta presentación.');
});

test('status presentation has no authorization or transition decisions', function () {
    $publicProperties = array_keys(get_class_vars(PayPeriodStatusPresentation::class));
    $publicMethods = array_map(
        fn (ReflectionMethod $method): string => $method->getName(),
        (new ReflectionClass(PayPeriodStatusPresentation::class))->getMethods(ReflectionMethod::IS_PUBLIC),
    );

    expect($publicProperties)->toBe([
        'status', 'known', 'label', 'badgeVariant', 'phaseKey', 'phaseIndex', 'copy',
    ])->and($publicMethods)->toContain('for', 'phases')
        ->not->toContain('canUpload', 'canReview', 'canApprove', 'canExport', 'transition');
});

test('workflow phases are fixed presentation labels', function () {
    expect(PayPeriodStatusPresentation::phases())->toBe([
        ['key' => 'period', 'index' => 1, 'label' => 'Período'],
        ['key' => 'upload', 'index' => 2, 'label' => 'Carga'],
        ['key' => 'review', 'index' => 3, 'label' => 'Revisión'],
        ['key' => 'process', 'index' => 4, 'label' => 'Proceso'],
        ['key' => 'finalize', 'index' => 5, 'label' => 'Aprobación y exportación'],
    ]);
});
