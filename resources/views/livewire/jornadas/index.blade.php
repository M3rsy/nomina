@php
    $canManageSchedules = auth()->user()->can('create', \App\Models\WorkSchedule::class);
    $selectedProfile = collect($profiles)->firstWhere('id', $selectedProfileId) ?? collect($profiles)->first();
    $selectedProfileName = $selectedProfile['name'] ?? 'Jornada general';
    $selectedProfileVersion = $selectedProfile['version'] ?? 1;
    $workingDaysCount = $this->getWorkingDaysCountProperty();
    $weeklyOrdinaryHours = $this->getWeeklyOrdinaryHoursProperty();
@endphp

<div class="min-h-screen bg-surface-muted" data-work-schedules-index="workspace">
    <x-ui.loading-overlay target="confirmHistoricalSave,createProfile,retireProfile,activateGeneralProfile,save" message="Validando y guardando la jornada…" />

    <div class="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
        <nav aria-label="Miga de pan" class="flex flex-wrap items-center gap-2 text-sm font-semibold text-text-muted">
            <span class="rounded-full bg-surface px-3 py-1 text-xs font-bold uppercase tracking-wider text-brand">Configuración de Jornada</span>
            <span aria-hidden="true">/</span>
            <span>Motor de Cálculo de Horas</span>
        </nav>

        <x-ui.page-header
            title="Jornadas de trabajo"
            description="Definí la semana base, versioná plantillas operativas y verificá cómo impactan los horarios en el cálculo automatizado de nómina."
        >
            @if ($canManageSchedules)
                <x-slot:actions>
                    <x-ui.loading-button
                        type="button"
                        variant="secondary"
                        wire:click="openCreateProfile"
                        target="openCreateProfile"
                        loading-label="Abriendo…"
                        :disabled="$requiresProfileMigration"
                    >
                        Nueva plantilla
                    </x-ui.loading-button>
                </x-slot:actions>
            @endif
        </x-ui.page-header>

        <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4" aria-label="Resumen de jornadas">
            <x-ui.card class="relative overflow-hidden">
                <div class="absolute -right-10 -top-10 size-28 rounded-full bg-brand/5" aria-hidden="true"></div>
                <p class="text-xs font-bold uppercase tracking-wider text-text-muted">Plantilla activa</p>
                <p class="mt-3 text-2xl font-black tracking-tight text-text">{{ $selectedProfileName }} · v{{ $selectedProfileVersion }}</p>
                <p class="mt-2 text-sm text-text-muted">Perfil seleccionado para editar o versionar.</p>
                <div class="mt-4 h-1.5 rounded-full bg-brand"></div>
            </x-ui.card>

            <x-ui.card>
                <p class="text-xs font-bold uppercase tracking-wider text-text-muted">Horas base semanales</p>
                <p class="mt-3 text-3xl font-black tracking-tight text-text">{{ number_format($weeklyOrdinaryHours, 2) }} <span class="text-base font-semibold text-text-muted">hrs</span></p>
                <p class="mt-2 text-sm text-text-muted">Suma real de horas ordinarias configuradas.</p>
            </x-ui.card>

            <x-ui.card>
                <p class="text-xs font-bold uppercase tracking-wider text-text-muted">Días laborables</p>
                <p class="mt-3 text-3xl font-black tracking-tight text-text">{{ $workingDaysCount }} <span class="text-base font-semibold text-text-muted">días</span></p>
                <p class="mt-2 text-sm text-text-muted">Calculado desde la plantilla semanal visible.</p>
            </x-ui.card>

            <x-ui.card>
                <p class="text-xs font-bold uppercase tracking-wider text-text-muted">Estado técnico</p>
                <p class="mt-3 text-2xl font-black tracking-tight {{ $this->getHasHistoricalImpactProperty() ? 'text-warning-strong' : 'text-success-strong' }}">
                    {{ $this->getHasHistoricalImpactProperty() ? 'Histórico activo' : 'Sin conflictos' }}
                </p>
                <p class="mt-2 text-sm text-text-muted">
                    {{ $this->getHasHistoricalImpactProperty() ? $this->historicalImpactSummary() : 'No hay nómina procesada persistida para esta empresa.' }}
                </p>
            </x-ui.card>
        </section>

        @if ($showSuccess)
            <x-ui.alert variant="success" class="shadow-sm">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <p class="font-semibold">Listo: versión guardada</p>
                        <p class="mt-1">La versión anterior conserva su historial y la nueva ya está disponible para asignar.</p>
                    </div>
                    <button type="button" wire:click="$set('showSuccess', false)" class="text-xs font-bold uppercase tracking-wide text-success-strong" aria-label="Cerrar mensaje de éxito">Cerrar</button>
                </div>
            </x-ui.alert>
        @endif

        @if ($showRetirementSuccess)
            <x-ui.alert variant="success" class="shadow-sm">
                <p class="font-semibold">Jornada retirada</p>
                <p class="mt-1">Las asignaciones vigentes y futuras ahora usan la jornada reemplazante.</p>
            </x-ui.alert>
        @endif

        @if ($requiresProfileMigration)
            <x-ui.alert variant="warning" class="shadow-sm">
                <p class="font-semibold">Faltan migraciones de jornadas</p>
                <p class="mt-2">No existe aún la tabla <code>work_schedule_profiles</code> en la base actual.</p>
                <p class="mt-1">Ejecutá <code>php artisan migrate --force</code> para completar la migración y habilitar perfiles/versiones.</p>
                @error('jornadas_profiles')
                    <p class="mt-2 font-semibold">{{ $message }}</p>
                @enderror
            </x-ui.alert>
        @endif

        @if ($showHistoricalImpactWarning)
            <x-ui.alert variant="warning" class="shadow-sm">
                <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                    <div>
                        <p class="text-sm font-bold uppercase tracking-wide">Impacto sobre históricos</p>
                        <p class="mt-1 text-sm">
                            @if ($this->getHasHistoricalImpactProperty())
                                {{ $this->historicalImpactSummary() }}
                            @else
                                No hay historial de nómina cerrado para esta compañía.
                            @endif
                        </p>
                        <p class="mt-2 text-sm">Si continuás, se creará una versión nueva. Los resultados históricos conservarán la versión anterior.</p>
                    </div>

                    <div class="flex shrink-0 flex-wrap gap-2">
                        <x-ui.loading-button type="button" wire:click="confirmHistoricalSave" target="confirmHistoricalSave" loading-label="Guardando…" class="inline-flex min-h-10 items-center rounded-xl bg-warning px-4 py-2 text-sm font-semibold text-white">
                            Confirmar y crear versión
                        </x-ui.loading-button>
                        <x-ui.button type="button" variant="secondary" wire:click="cancelHistoricalSave">Revisar ajustes</x-ui.button>
                    </div>
                </div>
            </x-ui.alert>
        @endif

        <section class="grid gap-6 xl:grid-cols-[minmax(0,1.65fr)_minmax(22rem,0.85fr)] xl:items-start">
            <div class="space-y-6">
                <x-ui.card aria-labelledby="weekly-schedule-heading" class="overflow-hidden">
                    <x-slot:header>
                        <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                            <div>
                                <p class="text-xs font-bold uppercase tracking-wider text-brand">Plantilla semanal de horarios</p>
                                <h2 id="weekly-schedule-heading" class="mt-1 text-xl font-bold text-text">Plantilla semanal de horarios</h2>
                                <p class="mt-1 text-sm text-text-muted">Define el horario real; una hora de fin menor indica que la jornada cruza medianoche.</p>
                            </div>

                            <div class="flex flex-wrap items-end gap-3">
                                <label class="text-xs font-bold uppercase tracking-wide text-text-muted">
                                    Plantilla
                                    <select wire:model.live="selectedProfileId" class="mt-1 block min-h-11 rounded-xl border border-border bg-surface px-3 text-sm text-text" @disabled($requiresProfileMigration)>
                                        @forelse ($profiles as $profile)
                                            <option value="{{ $profile['id'] }}">{{ $profile['name'] }} · v{{ $profile['version'] }}</option>
                                        @empty
                                            <option value="">Jornada general · sin guardar</option>
                                        @endforelse
                                    </select>
                                </label>

                                @if ($canManageSchedules && $selectedProfileId)
                                    <x-ui.loading-button
                                        type="button"
                                        role="switch"
                                        aria-checked="true"
                                        wire:click="openRetireProfile({{ $selectedProfileId }})"
                                        target="openRetireProfile({{ $selectedProfileId }})"
                                        loading-label="Abriendo…"
                                        class="inline-flex min-h-11 items-center gap-2 rounded-xl border border-success/30 bg-success/10 px-3 py-2 text-sm font-semibold text-success-strong"
                                    >
                                        <span class="size-2 rounded-full bg-success" aria-hidden="true"></span>
                                        Disponible
                                    </x-ui.loading-button>
                                @endif
                            </div>
                        </div>
                    </x-slot:header>

                    @if ($showCreateProfile)
                        <div class="mb-5 rounded-2xl border border-brand/20 bg-brand/5 p-4">
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
                                <label class="flex-1 text-sm font-semibold text-text">Nombre de la nueva plantilla
                                    <input type="text" wire:model="newProfileName" class="mt-1 w-full rounded-xl border border-border bg-surface px-3 py-2" placeholder="Ej. Guardia nocturna" />
                                    @error('newProfileName') <span class="mt-1 block text-xs text-danger-strong">{{ $message }}</span> @enderror
                                </label>
                                <x-ui.loading-button type="button" wire:click="createProfile" target="createProfile" loading-label="Duplicando…" class="inline-flex min-h-10 items-center rounded-xl bg-brand px-4 py-2 text-sm font-semibold text-white">Duplicar plantilla visible</x-ui.loading-button>
                                <x-ui.button type="button" variant="secondary" wire:click="cancelCreateProfile">Cancelar</x-ui.button>
                            </div>
                        </div>
                    @endif

                    @if ($showRetireProfile)
                        <div class="mb-5 rounded-2xl border border-warning/30 bg-warning/10 p-4">
                            <div class="grid gap-3 md:grid-cols-2">
                                <label class="text-sm font-semibold text-text">
                                    Jornada reemplazante
                                    <select wire:model="replacementProfileId" class="mt-1 w-full rounded-xl border border-border bg-surface px-3 py-2">
                                        <option value="">Seleccioná una jornada</option>
                                        @foreach ($profiles as $profile)
                                            @if ($profile['id'] !== $retiringProfileId)
                                                <option value="{{ $profile['id'] }}">{{ $profile['name'] }} · v{{ $profile['version'] }}</option>
                                            @endif
                                        @endforeach
                                    </select>
                                    @error('replacementProfileId') <span class="mt-1 block text-xs text-danger-strong">{{ $message }}</span> @enderror
                                </label>

                                <label class="text-sm font-semibold text-text">
                                    Motivo del retiro
                                    <input type="text" wire:model="retirementReason" maxlength="500" class="mt-1 w-full rounded-xl border border-border bg-surface px-3 py-2" />
                                    @error('retirementReason') <span class="mt-1 block text-xs text-danger-strong">{{ $message }}</span> @enderror
                                </label>
                            </div>

                            <p class="mt-3 text-sm text-text-muted">Se reasignarán {{ $retirementAffectedEmployeeCount }} empleados con referencias vigentes o futuras. Esta acción no se puede revertir.</p>

                            <div class="mt-3 flex justify-end gap-2">
                                <x-ui.button type="button" variant="secondary" wire:click="cancelRetireProfile">Cancelar</x-ui.button>
                                <x-ui.loading-button type="button" wire:click="retireProfile" target="retireProfile" loading-label="Retirando…" class="inline-flex min-h-10 items-center rounded-xl bg-warning px-4 py-2 text-sm font-semibold text-white">Retirar y reasignar</x-ui.loading-button>
                            </div>
                        </div>
                    @endif

                    <div class="overflow-hidden rounded-2xl border border-border">
                        <div class="overflow-x-auto">
                            <table class="min-w-full bg-surface text-sm">
                                <thead class="bg-surface-muted text-xs font-bold uppercase tracking-wide text-text-muted">
                                    <tr>
                                        <th class="px-4 py-3 text-left">Día</th>
                                        <th class="px-4 py-3 text-center">Laborable</th>
                                        <th class="px-4 py-3 text-left">Inicio</th>
                                        <th class="px-4 py-3 text-left">Fin</th>
                                        <th class="px-4 py-3 text-left">Horas base</th>
                                        <th class="px-4 py-3 text-left">Notas internas</th>
                                        <th class="px-4 py-3 text-right">Estado</th>
                                    </tr>
                                </thead>

                                <tbody class="divide-y divide-border">
                                    @foreach ($schedules as $index => $schedule)
                                        <tr class="transition {{ $schedule['is_working_day'] ? 'bg-surface hover:bg-surface-muted/60' : 'bg-surface-muted/70 text-text-muted' }}">
                                            <td class="px-4 py-3 align-top font-semibold text-text">
                                                <span class="inline-flex items-center gap-2">
                                                    <span class="size-2 rounded-full {{ $schedule['is_working_day'] ? 'bg-success' : 'bg-border' }}" aria-hidden="true"></span>
                                                    {{ $schedule['day_name'] }}
                                                </span>
                                            </td>

                                            <td class="px-4 py-3 text-center align-top">
                                                <label class="inline-flex items-center gap-2 text-sm text-text-muted">
                                                    <input
                                                        type="checkbox"
                                                        wire:model.live="schedules.{{ $index }}.is_working_day"
                                                        @disabled(! $canManageSchedules)
                                                        class="size-4 rounded border-border text-brand focus:ring-2 focus:ring-brand/30"
                                                        aria-label="Marcar {{ $schedule['day_name'] }} como día laborable"
                                                    />
                                                    <span class="sr-only">{{ $schedule['is_working_day'] ? 'Sí' : 'No' }}</span>
                                                </label>
                                            </td>

                                            @foreach (['start_time' => 'Inicio', 'end_time' => 'Fin'] as $field => $label)
                                                <td class="px-4 py-3 align-top">
                                                    <input type="time" wire:model.live="schedules.{{ $index }}.{{ $field }}" @disabled(! $canManageSchedules || ! $schedule['is_working_day']) aria-label="{{ $label }} de {{ $schedule['day_name'] }}" class="min-h-10 rounded-xl border border-border bg-surface px-3 text-sm text-text disabled:bg-surface-muted" />
                                                    @error("schedules.$index.$field") <p class="mt-1 text-xs text-danger-strong">{{ $message }}</p> @enderror
                                                </td>
                                            @endforeach

                                            <td class="px-4 py-3 align-top">
                                                <input
                                                    type="number"
                                                    step="0.25"
                                                    min="0"
                                                    max="24"
                                                    wire:model.live="schedules.{{ $index }}.base_ordinary_hours"
                                                    @disabled(! $canManageSchedules)
                                                    class="w-28 rounded-xl border border-border bg-surface px-3 py-2 text-sm text-text disabled:bg-surface-muted"
                                                    placeholder="0.00"
                                                />
                                                @if (! $schedule['is_working_day'])
                                                    <p class="mt-1 text-[11px] text-text-muted">No impacta</p>
                                                @endif
                                                @error("schedules.$index.base_ordinary_hours") <p class="mt-1 text-xs text-danger-strong">{{ $message }}</p> @enderror
                                            </td>

                                            <td class="px-4 py-3 align-top">
                                                <input
                                                    type="text"
                                                    wire:model.live="schedules.{{ $index }}.notes"
                                                    @disabled(! $canManageSchedules)
                                                    placeholder="Nota breve"
                                                    class="w-full min-w-48 rounded-xl border border-border bg-surface px-3 py-2 text-sm text-text disabled:bg-surface-muted"
                                                />
                                            </td>

                                            <td class="px-4 py-3 text-right align-top">
                                                <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-bold {{ $schedule['is_working_day'] ? 'bg-success/10 text-success-strong' : 'bg-surface-muted text-text-muted' }}">
                                                    {{ $schedule['is_working_day'] ? 'Activo' : 'Inactivo' }}
                                                </span>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        <div class="flex flex-col gap-2 border-t border-border bg-surface-muted px-4 py-3 text-sm text-text-muted sm:flex-row sm:items-center sm:justify-between">
                            <p class="font-semibold text-text">Días laborables: {{ $workingDaysCount }} · Horas base semanales: {{ number_format($weeklyOrdinaryHours, 2) }}</p>
                            <p class="text-xs">Nota: el valor base se usa para definir la franja ordinaria en procesos de nómina.</p>
                        </div>
                    </div>
                </x-ui.card>

                <x-ui.card aria-labelledby="schedule-versioning-heading">
                    <x-slot:header>
                        <p class="text-xs font-bold uppercase tracking-wider text-brand">Control de cambios y versionado</p>
                        <h2 id="schedule-versioning-heading" class="mt-1 text-xl font-bold text-text">Control de cambios y versionado</h2>
                        <p class="mt-1 text-sm text-text-muted">Cada cambio operativo queda versionado para proteger períodos históricos.</p>
                    </x-slot:header>

                    @if ($canManageSchedules)
                        <div class="grid gap-4 lg:grid-cols-2">
                            <label class="text-sm font-semibold text-text">Motivo de activación
                                <input type="text" wire:model="activationReason" class="mt-1 w-full rounded-xl border border-border bg-surface px-3 py-2" placeholder="Explicá por qué se activa la nueva política" />
                                @error('activationReason') <span class="mt-1 block text-xs text-danger-strong">{{ $message }}</span> @enderror
                            </label>

                            @if ($selectedProfileId)
                                <label class="text-sm font-semibold text-text">Motivo de la nueva versión
                                    <input type="text" wire:model="changeReason" class="mt-1 w-full rounded-xl border border-border bg-surface px-3 py-2" placeholder="Explicá por qué cambia la jornada" />
                                    @error('changeReason') <span class="mt-1 block text-xs text-danger-strong">{{ $message }}</span> @enderror
                                </label>
                            @endif
                        </div>

                        <div class="mt-4 flex flex-wrap justify-end gap-3">
                            <x-ui.loading-button type="button" wire:click="activateGeneralProfile" target="activateGeneralProfile" loading-label="Activando…" class="inline-flex min-h-11 items-center rounded-xl bg-success px-5 py-2.5 text-sm font-semibold text-white">
                                Activar jornada general
                            </x-ui.loading-button>
                            <x-ui.loading-button type="button" wire:click="save" target="save" loading-label="Guardando…" :disabled="$requiresProfileMigration" class="inline-flex min-h-11 items-center rounded-xl bg-brand px-5 py-2.5 text-sm font-semibold text-white disabled:opacity-70">
                                {{ $selectedProfileId ? 'Crear nueva versión' : 'Guardar plantilla inicial' }}
                            </x-ui.loading-button>
                        </div>
                    @else
                        <p class="text-sm text-text-muted">Tu rol puede consultar la configuración, pero no crear nuevas versiones.</p>
                    @endif
                </x-ui.card>
            </div>

            <aside class="space-y-6">
                <x-ui.card aria-labelledby="timebands-heading">
                    <x-slot:header>
                        <p class="text-xs font-bold uppercase tracking-wider text-brand">Motor de cálculo</p>
                        <h2 id="timebands-heading" class="mt-1 text-xl font-bold text-text">Bandas de recargo aplicadas</h2>
                        <p class="mt-1 text-sm text-text-muted">Las franjas siguientes ya se usan en el motor de cálculo automatizado.</p>
                    </x-slot:header>

                    <div class="rounded-2xl bg-surface-muted p-4">
                        <div class="flex items-center justify-between text-[11px] font-bold text-text-muted">
                            <span>00:00</span><span>06:00</span><span>12:00</span><span>18:00</span><span>24:00</span>
                        </div>
                        <div class="mt-2 flex h-6 overflow-hidden rounded-xl shadow-sm" aria-hidden="true">
                            <div class="grid place-items-center bg-warning/30 text-[10px] font-bold text-warning-strong" style="width:25%">+75%</div>
                            <div class="grid place-items-center bg-success/30 text-[10px] font-bold text-success-strong" style="width:33.33%">100%</div>
                            <div class="grid place-items-center bg-info/30 text-[10px] font-bold text-info-strong" style="width:16.66%">+25%</div>
                            <div class="grid place-items-center bg-brand/20 text-[10px] font-bold text-brand" style="width:25.01%">+50%</div>
                        </div>
                    </div>

                    <ul class="mt-4 space-y-3">
                        @foreach ($timeBandProfile as $band)
                            <li class="flex items-center justify-between gap-3 rounded-2xl border px-3 py-3 {{ $band['color'] }}">
                                <div>
                                    <p class="font-bold">{{ $band['label'] }}</p>
                                    <p class="mt-0.5 text-xs font-semibold uppercase tracking-wide">{{ $band['start'] }} – {{ $band['end'] }}</p>
                                </div>
                                <span class="rounded-full bg-white/70 px-2 py-1 text-xs font-black">{{ $band['rate'] }}</span>
                            </li>
                        @endforeach
                    </ul>
                </x-ui.card>

                <x-ui.card aria-labelledby="technical-checklist-heading">
                    <x-slot:header>
                        <h2 id="technical-checklist-heading" class="text-xl font-bold text-text">Checklist de validación técnica</h2>
                        <p class="mt-1 text-sm text-text-muted">Comprobaciones automáticas que protegen consistencia legal y algorítmica.</p>
                    </x-slot:header>

                    <button type="button" wire:click="$toggle('showTimebandPreview')" class="mb-4 inline-flex min-h-10 items-center rounded-xl border border-border bg-surface px-3 py-2 text-sm font-semibold text-text transition hover:bg-surface-muted">
                        {{ $showTimebandPreview ? 'Ocultar checklist técnico' : 'Ver checklist técnico' }}
                    </button>

                    <ul class="space-y-2">
                        @foreach ($technicalReadinessItems as $item)
                            <li class="rounded-xl border border-border bg-surface-muted px-3 py-2 text-sm leading-6 text-text-muted {{ $showTimebandPreview ? '' : 'hidden first:block' }}">
                                {{ $item }}
                            </li>
                        @endforeach
                    </ul>
                </x-ui.card>
            </aside>
        </section>

        @if ($profileHistory !== [])
            <x-ui.card aria-labelledby="schedule-history-heading">
                <x-slot:header>
                    <h2 id="schedule-history-heading" class="text-xl font-bold text-text">Historial de jornadas</h2>
                    <p class="mt-1 text-sm text-text-muted">Las jornadas retiradas y las versiones reemplazadas son de solo lectura.</p>
                </x-slot:header>

                <div class="overflow-x-auto rounded-2xl border border-border">
                    <table class="min-w-full divide-y divide-border text-sm">
                        <thead class="bg-surface-muted text-left text-xs font-bold uppercase tracking-wide text-text-muted">
                            <tr>
                                <th class="px-3 py-2">Jornada</th>
                                <th class="px-3 py-2">Estado</th>
                                <th class="px-3 py-2">Fecha</th>
                                <th class="px-3 py-2">Responsable</th>
                                <th class="px-3 py-2">Motivo</th>
                                <th class="px-3 py-2">Reemplazo</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border bg-surface">
                            @foreach ($profileHistory as $profile)
                                <tr>
                                    <td class="px-3 py-3 font-semibold text-text">{{ $profile['name'] }} · v{{ $profile['version'] }}</td>
                                    <td class="px-3 py-3">
                                        <span class="rounded-full px-2 py-1 text-xs font-semibold {{ $profile['status'] === 'retired' ? 'bg-warning/10 text-warning-strong' : 'bg-surface-muted text-text-muted' }}">
                                            {{ $profile['status'] === 'retired' ? 'Retirada' : 'Versión reemplazada' }}
                                        </span>
                                    </td>
                                    <td class="px-3 py-3 text-text-muted">{{ $profile['date'] ?? '—' }}</td>
                                    <td class="px-3 py-3 text-text-muted">{{ $profile['actor'] ?? '—' }}</td>
                                    <td class="px-3 py-3 text-text-muted">{{ $profile['reason'] ?? '—' }}</td>
                                    <td class="px-3 py-3 text-text-muted">{{ $profile['replacement'] ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-ui.card>
        @endif
    </div>
</div>
