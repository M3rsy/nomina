<div
    data-payroll-index
    class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8"
    x-data="{ deleteTrigger: null }"
    x-on:payroll-delete-closed.window="$nextTick(() => deleteTrigger?.focus())"
>
    <nav aria-label="Ruta de navegación" class="mb-4 flex flex-wrap items-center gap-2 text-sm font-medium text-text-muted">
        <span>Gestión de Nómina</span>
        <span aria-hidden="true">/</span>
        <span class="text-text">Ciclos y Calendario</span>
    </nav>

    <div class="rounded-3xl border border-border bg-surface px-5 py-6 shadow-sm sm:px-8 sm:py-8">
        <x-ui.page-header
            title="Períodos de Nómina"
            description="Organizá cada ciclo, reconocé su fase actual y continuá únicamente con las acciones disponibles para tu rol."
        >
            @if ($canCreate)
                <x-slot:actions>
                    <x-ui.button
                        id="create-period-trigger"
                        wire:click="openCreateForm"
                        aria-expanded="{{ $showCreateForm ? 'true' : 'false' }}"
                        aria-controls="create-period-panel"
                    >
                        Crear período
                    </x-ui.button>
                </x-slot:actions>
            @endif
        </x-ui.page-header>
        <p class="mt-5 max-w-3xl border-t border-border pt-5 text-sm leading-6 text-text-muted">
            Consultá el historial visible de períodos y avanzá desde la preparación hasta el cierre sin salir del flujo de nómina.
        </p>
    </div>

    @if ($hasCompany)
        @php
            $visiblePeriods = $payPeriods->getCollection();
            $visiblePeriodCount = $visiblePeriods->count();
            $draftPeriodCount = $visiblePeriods->where('status', 'draft')->count();
            $completedPeriodCount = $visiblePeriods->whereIn('status', ['processed', 'approved', 'exported'])->count();
            $availableActionCount = collect($periodActions)
                ->sum(fn (array $actions): int => collect($actions)->filter()->count());
        @endphp

        <section
            data-payroll-summary
            aria-label="Resumen de períodos visibles"
            class="mt-6 grid gap-3 sm:grid-cols-2 xl:grid-cols-4"
        >
            <article data-summary-visible-periods="{{ $visiblePeriodCount }}" class="rounded-2xl border border-border bg-surface p-5 shadow-sm">
                <p class="text-sm font-medium text-text-muted">Períodos visibles</p>
                <p class="mt-2 text-3xl font-bold text-text">{{ $visiblePeriodCount }}</p>
                <p class="mt-1 text-xs text-text-muted">En esta página</p>
            </article>
            <article data-summary-draft-periods="{{ $draftPeriodCount }}" class="rounded-2xl border border-border bg-surface p-5 shadow-sm">
                <p class="text-sm font-medium text-text-muted">Borradores</p>
                <p class="mt-2 text-3xl font-bold text-text">{{ $draftPeriodCount }}</p>
                <p class="mt-1 text-xs text-text-muted">Listos para iniciar la carga</p>
            </article>
            <article data-summary-completed-periods="{{ $completedPeriodCount }}" class="rounded-2xl border border-border bg-surface p-5 shadow-sm">
                <p class="text-sm font-medium text-text-muted">Finalizados</p>
                <p class="mt-2 text-3xl font-bold text-text">{{ $completedPeriodCount }}</p>
                <p class="mt-1 text-xs text-text-muted">Procesados, aprobados o exportados</p>
            </article>
            <article data-summary-available-actions="{{ $availableActionCount }}" class="rounded-2xl border border-border bg-surface p-5 shadow-sm">
                <p class="text-sm font-medium text-text-muted">Acciones disponibles</p>
                <p class="mt-2 text-3xl font-bold text-text">{{ $availableActionCount }}</p>
                <p class="mt-1 text-xs text-text-muted">Según estado y permisos</p>
            </article>
        </section>

        <x-ui.card class="mt-6" aria-labelledby="workflow-heading">
            <x-slot:header>
                <h2 id="workflow-heading" class="font-semibold text-text">Cinco fases del flujo</h2>
                <p class="mt-1 text-sm text-text-muted">La fase orienta; el estado y los permisos siguen controlando cada acción.</p>
            </x-slot:header>
            <x-nomina.payroll-workflow :phases="$phases" />
        </x-ui.card>
    @endif

    @if ($showCreateForm)
        <x-ui.card id="create-period-panel" class="mt-6" aria-labelledby="create-period-heading">
            <x-slot:header>
                <p class="text-xs font-semibold uppercase tracking-wide text-brand">Nuevo ciclo</p>
                <h2 id="create-period-heading" class="mt-1 text-xl font-bold text-text">Creá un período de nómina</h2>
                <p class="mt-1 text-sm text-text-muted">Completá los datos del ciclo. Al crearlo, vas a continuar con la carga de asistencia.</p>
            </x-slot:header>

            <ol data-create-period-steps aria-label="Pasos para crear un período" class="mb-6 grid gap-3 border-b border-border pb-6 sm:grid-cols-3">
                <li class="flex gap-3 rounded-2xl bg-surface-muted p-4">
                    <span class="flex size-7 shrink-0 items-center justify-center rounded-full bg-brand text-xs font-bold text-white">1</span>
                    <div>
                        <p class="text-sm font-semibold text-text">Identificá el período</p>
                        <p class="mt-1 text-xs text-text-muted">Asignale un nombre reconocible.</p>
                    </div>
                </li>
                <li class="flex gap-3 rounded-2xl bg-surface-muted p-4">
                    <span class="flex size-7 shrink-0 items-center justify-center rounded-full border border-border bg-surface text-xs font-bold text-text">2</span>
                    <div>
                        <p class="text-sm font-semibold text-text">Definí las fechas</p>
                        <p class="mt-1 text-xs text-text-muted">Indicá el inicio y el fin del ciclo.</p>
                    </div>
                </li>
                <li class="flex gap-3 rounded-2xl bg-surface-muted p-4">
                    <span class="flex size-7 shrink-0 items-center justify-center rounded-full border border-border bg-surface text-xs font-bold text-text">3</span>
                    <div>
                        <p class="text-sm font-semibold text-text">Continuá con la carga</p>
                        <p class="mt-1 text-xs text-text-muted">El siguiente paso conserva el flujo actual.</p>
                    </div>
                </li>
            </ol>

            <form id="create-period-form" wire:submit="store" class="grid gap-5 lg:grid-cols-2">
                <div class="lg:col-span-2">
                    <x-ui.input
                        id="period-name"
                        label="Nombre del período"
                        hint="Usá un nombre que puedas reconocer en la lista."
                        wire:model="name"
                        maxlength="120"
                        required
                        autocomplete="off"
                        :error="$errors->first('name')"
                    />
                </div>
                <x-ui.input
                    id="period-start-date"
                    type="date"
                    label="Fecha de inicio"
                    wire:model="start_date"
                    required
                    :error="$errors->first('start_date')"
                />
                <x-ui.input
                    id="period-end-date"
                    type="date"
                    label="Fecha de fin"
                    wire:model="end_date"
                    required
                    :error="$errors->first('end_date')"
                />
                <div class="flex flex-col-reverse gap-3 border-t border-border pt-5 sm:flex-row sm:justify-end lg:col-span-2">
                    <x-ui.button variant="secondary" wire:click="closeCreateForm">Cancelar</x-ui.button>
                    <x-ui.loading-button
                        type="submit"
                        target="store"
                        loading-label="Creando período…"
                        class="inline-flex min-h-11 items-center justify-center rounded-xl bg-brand px-5 py-2.5 text-sm font-semibold text-white"
                    >
                        Crear y continuar
                    </x-ui.loading-button>
                </div>
            </form>
        </x-ui.card>
    @endif

    @if (! $hasCompany)
        <x-ui.empty-state
            data-payroll-company-context
            title="Seleccioná una empresa para continuar"
            class="mx-auto mt-8 max-w-2xl"
        >
            La nómina siempre corresponde a una empresa activa. Elegí una para consultar períodos, cargar marcas y procesar resultados sin mezclar información entre empresas.
            <x-slot:actions>
                <x-ui.button x-on:click.stop="$dispatch('open-company-selector')">Seleccionar empresa</x-ui.button>
                <x-ui.button :href="route('dashboard')" variant="secondary" wire:navigate>Volver al panel</x-ui.button>
            </x-slot:actions>
        </x-ui.empty-state>
    @else
        <section aria-labelledby="period-list-heading" class="mt-8">
            <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-brand">Ciclos y calendario</p>
                    <h2 id="period-list-heading" class="mt-1 text-xl font-bold text-text">Períodos existentes</h2>
                    <p class="mt-1 text-sm text-text-muted">Cada período muestra su estado exacto, su explicación y solo las acciones autorizadas.</p>
                </div>
                <p class="rounded-full bg-surface-muted px-3 py-1.5 text-xs font-semibold text-text-muted">
                    {{ $visiblePeriodCount }} {{ $visiblePeriodCount === 1 ? 'período visible' : 'períodos visibles' }}
                </p>
            </div>

            @if ($payPeriods->isEmpty())
                <x-ui.empty-state title="Todavía no hay períodos de nómina.">
                    Creá un período para definir sus fechas y continuar con la carga de asistencia.
                    @if ($canCreate)
                        <x-slot:actions>
                            <x-ui.button wire:click="openCreateForm">Crear el primer período</x-ui.button>
                        </x-slot:actions>
                    @endif
                </x-ui.empty-state>
            @endif

            <div data-period-desktop-list class="hidden overflow-hidden rounded-3xl border border-border bg-surface shadow-sm lg:block">
                <table class="w-full text-sm">
                    <thead class="bg-surface-muted text-text-muted">
                        <tr>
                            <th scope="col" class="px-5 py-3 text-left font-semibold">Período</th>
                            <th scope="col" class="px-5 py-3 text-left font-semibold">Fechas</th>
                            <th scope="col" class="px-5 py-3 text-left font-semibold">Estado y orientación</th>
                            <th scope="col" class="px-5 py-3 text-left font-semibold">Acciones</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @foreach ($payPeriods as $payPeriod)
                            @php
                                $presentation = $periodPresentations[$payPeriod->id];
                                $actions = $periodActions[$payPeriod->id];
                            @endphp
                            <tr>
                                <th scope="row" class="px-5 py-5 text-left font-semibold text-text">{{ $payPeriod->name ?? $payPeriod->slug }}</th>
                                <td class="px-5 py-5 text-text-muted">
                                    {{ $payPeriod->start_date->format('d/m/Y') }} – {{ $payPeriod->end_date->format('d/m/Y') }}
                                </td>
                                <td class="max-w-md px-5 py-5">
                                    <x-ui.badge :variant="$presentation->badgeVariant">{{ $presentation->label }}</x-ui.badge>
                                    <p class="mt-2 text-sm leading-5 text-text-muted">{{ $presentation->copy }}</p>
                                </td>
                                <td class="px-5 py-5">
                                    <div class="flex flex-wrap gap-2">
                                        @if ($actions['upload'])
                                            <x-ui.button :href="route('archivos.upload', ['pay_period_id' => $payPeriod->id])" wire:navigate>Cargar marcas</x-ui.button>
                                        @endif
                                        @if ($actions['review'])
                                            <x-ui.button :href="route('nomina.revisar', $payPeriod)" variant="secondary" wire:navigate>Revisar</x-ui.button>
                                        @endif
                                        @if ($actions['delete'])
                                            <x-ui.button
                                                variant="danger"
                                                wire:click="openDeleteConfirmation({{ $payPeriod->id }})"
                                                x-on:click="deleteTrigger = $el"
                                            >
                                                Eliminar
                                            </x-ui.button>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div data-period-mobile-list class="grid gap-4 lg:hidden" aria-label="Períodos de nómina en vista compacta">
                @foreach ($payPeriods as $payPeriod)
                    @php
                        $presentation = $periodPresentations[$payPeriod->id];
                        $actions = $periodActions[$payPeriod->id];
                    @endphp
                    <x-ui.card aria-labelledby="period-{{ $payPeriod->id }}-heading">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <h3 id="period-{{ $payPeriod->id }}-heading" class="font-semibold text-text">{{ $payPeriod->name ?? $payPeriod->slug }}</h3>
                                <p class="mt-1 text-sm text-text-muted">{{ $payPeriod->start_date->format('d/m/Y') }} – {{ $payPeriod->end_date->format('d/m/Y') }}</p>
                            </div>
                            <x-ui.badge :variant="$presentation->badgeVariant">{{ $presentation->label }}</x-ui.badge>
                        </div>
                        <p class="mt-3 text-sm leading-6 text-text-muted">{{ $presentation->copy }}</p>
                        <div class="mt-4 flex flex-wrap gap-2">
                            @if ($actions['upload'])
                                <x-ui.button :href="route('archivos.upload', ['pay_period_id' => $payPeriod->id])" wire:navigate>Cargar marcas</x-ui.button>
                            @endif
                            @if ($actions['review'])
                                <x-ui.button :href="route('nomina.revisar', $payPeriod)" variant="secondary" wire:navigate>Revisar</x-ui.button>
                            @endif
                            @if ($actions['delete'])
                                <x-ui.button
                                    variant="danger"
                                    wire:click="openDeleteConfirmation({{ $payPeriod->id }})"
                                    x-on:click="deleteTrigger = $el"
                                >
                                    Eliminar
                                </x-ui.button>
                            @endif
                        </div>
                    </x-ui.card>
                @endforeach
            </div>

            @if ($payPeriods->hasPages())
                <div class="mt-4">{{ $payPeriods->links() }}</div>
            @endif
        </section>
    @endif

    @if ($deletingPeriodId)
        <div
            class="fixed inset-0 z-50 flex items-center justify-center bg-text/50 p-4"
            role="presentation"
            x-init="$nextTick(() => $refs.deleteReason.focus())"
            x-on:keydown.escape.window="if (getComputedStyle($refs.deleteBusy).display === 'none') { $wire.closeDeleteConfirmation(); $dispatch('payroll-delete-closed') }"
        >
            <div
                class="w-full max-w-lg rounded-3xl border border-border bg-surface p-6 shadow-2xl"
                role="dialog"
                aria-modal="true"
                aria-labelledby="delete-period-heading"
                aria-describedby="delete-period-description"
                wire:loading.attr="aria-busy"
                wire:target="deletePeriod"
                x-on:keydown.tab="
                    const controls = [...$el.querySelectorAll('textarea, button:not([disabled])')];
                    if ($event.shiftKey && document.activeElement === controls[0]) { $event.preventDefault(); controls.at(-1).focus(); }
                    if (! $event.shiftKey && document.activeElement === controls.at(-1)) { $event.preventDefault(); controls[0].focus(); }
                "
            >
                <p class="text-xs font-semibold uppercase tracking-wide text-danger-strong">Acción irreversible</p>
                <h2 id="delete-period-heading" class="mt-1 text-xl font-bold text-text">Eliminar nómina</h2>
                <p id="delete-period-description" class="mt-2 text-sm leading-6 text-text-muted">
                    Se eliminarán el período y sus archivos asociados. El motivo y la persona responsable quedarán registrados en auditoría.
                </p>
                <form wire:submit="deletePeriod" class="mt-5 space-y-4">
                    <x-ui.textarea
                        id="period-deletion-reason"
                        label="Motivo de eliminación"
                        wire:model="deletionReason"
                        rows="4"
                        maxlength="500"
                        required
                        x-ref="deleteReason"
                        :error="$errors->first('deletionReason')"
                    >{{ $deletionReason }}</x-ui.textarea>
                    <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                        <x-ui.button
                            variant="secondary"
                            wire:click="closeDeleteConfirmation"
                            wire:loading.attr="disabled"
                            wire:target="deletePeriod"
                            x-on:click="$dispatch('payroll-delete-closed')"
                        >
                            Cancelar
                        </x-ui.button>
                        <x-ui.loading-button
                            type="submit"
                            target="deletePeriod"
                            loading-label="Eliminando…"
                            class="inline-flex min-h-11 items-center justify-center rounded-xl bg-danger px-4 py-2.5 text-sm font-semibold text-white"
                        >
                            Eliminar nómina
                        </x-ui.loading-button>
                    </div>
                    <span x-ref="deleteBusy" wire:loading wire:target="deletePeriod" class="sr-only">Eliminación en curso.</span>
                </form>
            </div>
        </div>
    @endif
</div>
