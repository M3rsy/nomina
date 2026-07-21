<div class="min-h-screen bg-[radial-gradient(circle_at_top,_#e8fbfb_0%,_#f5f3ff_35%,_#fff_70%)] px-4 py-6 sm:px-6 lg:px-8">
    <div class="mx-auto w-full max-w-6xl space-y-5">
        <section class="rounded-3xl border border-slate-200/70 bg-white/90 p-6 shadow-sm backdrop-blur">
            <div class="flex flex-col gap-2">
                <p class="inline-flex w-fit items-center gap-2 rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold uppercase tracking-[0.18em] text-slate-600">
                    Configuración interna
                </p>

                <h1 class="text-3xl font-black tracking-tight text-slate-900">Jornadas de trabajo</h1>
                <p class="max-w-2xl text-sm leading-relaxed text-slate-600">
                    Define tu semana base y consulta qué tan sensibles son tus cambios para la nómina ya cerrada.
                    El sistema calcula recargos por tramo automáticamente.
                </p>
            </div>
        </section>

        @if ($showSuccess)
            <section class="rounded-3xl border border-emerald-200 bg-emerald-50 p-5 text-sm text-emerald-800 shadow-sm">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <p class="font-semibold">Listo: configuración actualizada</p>
                        <p class="mt-1 text-emerald-700">
                            Los cambios quedaron guardados y se recalcularán en el próximo proceso de nómina.
                        </p>
                    </div>

                    <button
                        type="button"
                        wire:click="$set('showSuccess', false)"
                        class="rounded-full border border-emerald-200 px-2 py-1 text-xs font-semibold uppercase tracking-wide text-emerald-700 transition hover:bg-emerald-100"
                        aria-label="Cerrar mensaje de éxito"
                    >
                        Cerrar
                    </button>
                </div>
            </section>
        @endif

        @if ($showHistoricalImpactWarning)
            <section class="rounded-3xl border border-amber-300 bg-amber-50 p-5 shadow-sm">
                <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                    <div>
                        <p class="text-sm font-semibold uppercase tracking-wide text-amber-800">Impacto sobre históricos</p>
                        <p class="mt-1 text-sm text-amber-900">
                        @if ($this->getHasHistoricalImpactProperty())
                            {{ $this->historicalImpactSummary() }}
                        @else
                            No hay historial de nómina cerrado para esta compañía.
                        @endif
                        </p>
                        <p class="mt-2 text-sm text-amber-800">
                            Si continúas, se guardarán de todas formas y deberás volver a correr los procesos de nómina
                            para alinear los resultados afectados.
                        </p>
                    </div>

                    <div class="mt-2 flex shrink-0 gap-2 md:mt-0">
                        <button
                            type="button"
                            wire:click="confirmHistoricalSave"
                            class="inline-flex items-center rounded-full border border-amber-300 bg-amber-500 px-4 py-2 text-sm font-semibold text-white transition hover:bg-amber-600"
                        >
                            Confirmar y guardar
                        </button>

                        <button
                            type="button"
                            wire:click="cancelHistoricalSave"
                            class="inline-flex items-center rounded-full border border-amber-200 bg-white px-4 py-2 text-sm font-semibold text-amber-700 transition hover:bg-amber-100"
                        >
                            Revisar ajustes
                        </button>
                    </div>
                </div>
            </section>
        @endif

        <section class="grid gap-4 lg:grid-cols-[1.2fr_0.8fr]">
            <article class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
                <header class="mb-4 flex flex-col gap-2">
                    <h2 class="text-xl font-semibold text-slate-900">Plantilla semanal</h2>
                    <p class="text-sm text-slate-600">Marca días laborables y completa el bloque base de horas ordinarias.</p>
                </header>

                <div class="overflow-hidden rounded-2xl border border-slate-200">
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200 bg-white text-sm">
                            <thead class="bg-slate-50/90 text-slate-600">
                                 <tr>
                                     <th class="px-4 py-3 text-left font-semibold uppercase tracking-wide">Día</th>
                                     <th class="px-4 py-3 text-left font-semibold uppercase tracking-wide">Laborable</th>
                                     <th class="px-4 py-3 text-left font-semibold uppercase tracking-wide">Horas base</th>
                                     <th class="px-4 py-3 text-left font-semibold uppercase tracking-wide">Notas internas</th>
                                     <th class="px-4 py-3 text-left font-semibold uppercase tracking-wide">Bandas</th>
                                 </tr>
                            </thead>

                            <tbody class="divide-y divide-slate-200">
                                @foreach ($schedules as $index => $schedule)
                                    <tr class="transition {{ $schedule['is_working_day'] ? 'bg-white' : 'bg-slate-50/70 text-slate-500' }}">
                                        <td class="px-4 py-3 align-top font-medium text-slate-900">{{ $schedule['day_name'] }}</td>

                                        <td class="px-4 py-3 align-top">
                                            <label class="inline-flex items-center gap-2 text-sm text-slate-700">
                                                <input
                                                    type="checkbox"
                                                    wire:model.live="schedules.{{ $index }}.is_working_day"
                                                    class="h-4 w-4 rounded border-slate-300 text-emerald-600 focus:ring-2 focus:ring-emerald-500"
                                                    aria-label="Marcar {{ $schedule['day_name'] }} como día laborable"
                                                />
                                                {{ $schedule['is_working_day'] ? 'Sí' : 'No' }}
                                            </label>
                                        </td>

                                        <td class="px-4 py-3 align-top">
                                            <input
                                                type="number"
                                                step="0.25"
                                                min="0"
                                                max="24"
                                                wire:model.live="schedules.{{ $index }}.base_ordinary_hours"
                                                class="w-28 rounded-xl border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-emerald-400 focus:ring-2 focus:ring-emerald-100 disabled:cursor-not-allowed disabled:bg-slate-100"
                                                placeholder="0.00"
                                            />
                                            @if (! $schedule['is_working_day'])
                                                <p class="mt-1 text-[11px] text-slate-500">No laborable: no impactará en nómina.</p>
                                            @endif
                                            @error("schedules.$index.base_ordinary_hours")
                                                <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                                            @enderror
                                        </td>

                                        <td class="px-4 py-3 align-top">
                                            <input
                                                type="text"
                                                wire:model.live="schedules.{{ $index }}.notes"
                                                placeholder="Nota breve"
                                                class="w-full rounded-xl border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-emerald-400 focus:ring-2 focus:ring-emerald-100 disabled:cursor-not-allowed disabled:bg-slate-100"
                                            />
                                        </td>

                                        <td class="px-4 py-3 align-top">
                                            <textarea
                                                rows="4"
                                                wire:model.live="schedules.{{ $index }}.banding_json"
                                                placeholder='[{"start":"06:00","end":"14:00","extra_percent":0},...]'
                                                class="h-full w-full min-h-16 rounded-xl border-slate-300 bg-white px-3 py-2 font-mono text-xs text-slate-900 shadow-sm focus:border-emerald-400 focus:ring-2 focus:ring-emerald-100 disabled:cursor-not-allowed disabled:bg-slate-100"
                                            ></textarea>
                                            <p class="mt-1 text-[11px] text-slate-500">JSON editable por día para cambiar recargos. Sin valor: template por defecto.</p>
                                            @error("schedules.$index.banding_json")
                                                <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                                            @enderror
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="border-t border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700">
                        <p class="font-semibold text-slate-900">
                            Días laborables: {{ $this->getWorkingDaysCountProperty() }} · Horas base semanales: {{ number_format($this->getWeeklyOrdinaryHoursProperty(), 2) }}
                        </p>
                        <p class="mt-1 text-xs text-slate-600">Nota: el valor base se usa para definir la franja ordinaria en procesos de nómina.</p>
                    </div>
                </div>

                <div class="mt-5 flex justify-end">
                    @can('work_schedules.manage')
                        <button
                            type="button"
                            wire:click="save"
                            wire:loading.attr="disabled"
                            wire:loading.class="cursor-not-allowed opacity-70"
                            wire:target="save,confirmHistoricalSave"
                            class="inline-flex items-center gap-2 rounded-full bg-slate-900 px-5 py-2.5 text-sm font-semibold text-white shadow transition hover:bg-slate-800 disabled:opacity-70"
                        >
                            <span wire:loading.remove wire:target="save">Guardar configuración</span>
                            <span wire:loading wire:target="save" class="inline-flex items-center gap-2">
                                <svg class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 100 8V20A8 8 0 014 12z"></path>
                                </svg>
                                Guardando...
                            </span>
                        </button>
                    @endcan
                </div>
            </article>

            <aside class="space-y-4">
                <article class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
                    <h3 class="text-lg font-semibold text-slate-900">Bandas de recargo aplicadas</h3>
                    <p class="mt-1 text-sm text-slate-600">Las franjas siguientes ya se usan en el motor de cálculo.</p>

                    <ul class="mt-4 space-y-2">
                        @foreach ($timeBandProfile as $band)
                            <li class="rounded-2xl border px-3 py-2 {{ $band['color'] }} flex items-center justify-between gap-2">
                                <span class="font-semibold">{{ $band['label'] }}</span>
                                <span class="text-xs font-semibold uppercase tracking-[0.15em]">{{ $band['start'] }} – {{ $band['end'] }}</span>
                                <span class="rounded-full bg-white/70 px-2 py-0.5 text-[11px] font-bold">{{ $band['rate'] }}</span>
                            </li>
                        @endforeach
                    </ul>

                    <button
                        type="button"
                        wire:click="$toggle('showTimebandPreview')"
                        class="mt-4 inline-flex items-center rounded-full border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-700 transition hover:bg-slate-100"
                    >
                        {{ $showTimebandPreview ? 'Ocultar checklist técnico' : 'Ver checklist técnico' }}
                    </button>

                    @if ($showTimebandPreview)
                        <ul class="mt-3 space-y-2">
                            @foreach ($technicalReadinessItems as $item)
                                <li class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-xs leading-relaxed text-slate-700">
                                    {{ $item }}
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </article>

                <article class="rounded-3xl border border-emerald-200 bg-emerald-50 p-5">
                    <h3 class="text-lg font-semibold text-emerald-900">Estado técnico</h3>
                    @if ($this->getHasHistoricalImpactProperty())
                        <p class="mt-1 text-sm text-emerald-800">{{ $this->historicalImpactSummary() }}</p>
                    @else
                        <p class="mt-1 text-sm text-emerald-800">No hay nómina procesada persistida para esta empresa.</p>
                    @endif
                </article>
            </aside>
        </section>
    </div>
</div>
