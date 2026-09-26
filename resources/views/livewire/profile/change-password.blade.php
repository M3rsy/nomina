@php
    $accountUser = auth()->user();
    $accountInitial = mb_strtoupper(mb_substr(trim($accountUser->name), 0, 1));
    $accountRole = $accountUser->hasRole('super_admin')
        ? 'Superadministrador'
        : ($accountUser->hasRole('company_admin') ? 'Administrador de empresa' : 'Usuario');
    $accountScope = $accountUser->company?->name
        ?? ($accountUser->hasRole('super_admin') ? 'Acceso global' : 'Sin empresa asignada');
@endphp

<div
    class="mx-auto max-w-7xl space-y-6 py-6 sm:py-8"
    x-data="{
        currentPassword: '',
        newPassword: '',
        confirmation: '',
        showCurrent: false,
        showNew: false,
        showConfirmation: false,
        get hasMinimumLength() { return this.newPassword.length >= 8 },
        get differsFromCurrent() { return this.currentPassword.length > 0 && this.newPassword.length > 0 && this.newPassword !== this.currentPassword },
        get confirmationMatches() { return this.newPassword.length > 0 && this.confirmation.length > 0 && this.newPassword === this.confirmation },
        get strengthEstimate() {
            if (! this.newPassword.length) return 'Sin evaluar';
            let score = Number(this.newPassword.length >= 12)
                + Number(/[a-z]/.test(this.newPassword) && /[A-Z]/.test(this.newPassword))
                + Number(/[0-9]/.test(this.newPassword))
                + Number(/[^A-Za-z0-9]/.test(this.newPassword));
            return score >= 4 ? 'Alta' : (score >= 2 ? 'Media' : 'Baja');
        }
    }"
>
    <header class="overflow-hidden rounded-3xl border border-border bg-surface shadow-sm">
        <div class="px-5 py-7 sm:px-8 sm:py-9">
            <span class="inline-flex items-center gap-2 rounded-full border border-brand/20 bg-brand/10 px-3 py-1 text-xs font-bold uppercase tracking-wider text-brand">
                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 3 5 6v5c0 4.6 2.9 8.7 7 10 4.1-1.3 7-5.4 7-10V6l-7-3Z" />
                    <path stroke-linecap="round" stroke-linejoin="round" d="m9.5 12 1.7 1.7 3.6-4" />
                </svg>
                Seguridad de la cuenta
            </span>
            <h1 class="mt-4 text-3xl font-black tracking-tight text-text sm:text-4xl">Cambiar contraseña</h1>
            <p class="mt-3 max-w-2xl text-sm leading-6 text-text-muted sm:text-base">
                Actualizá tu contraseña para mantener protegido el acceso a tu cuenta.
            </p>
        </div>
    </header>

    @if (session('status'))
        <x-ui.alert id="change-password-status" variant="success">
            {{ session('status') }}
        </x-ui.alert>
    @endif

    <section aria-labelledby="account-context-heading" class="rounded-3xl border border-border bg-surface p-5 shadow-sm sm:p-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center">
            <div class="flex size-14 shrink-0 items-center justify-center rounded-2xl bg-brand text-xl font-black text-white" aria-hidden="true">
                {{ $accountInitial }}
            </div>
            <div class="min-w-0 flex-1">
                <p id="account-context-heading" class="text-xs font-bold uppercase tracking-wider text-text-muted">Cuenta autenticada</p>
                <p class="mt-1 truncate text-lg font-black text-text">{{ $accountUser->name }}</p>
                <p class="truncate text-sm text-text-muted">{{ $accountUser->email }}</p>
            </div>
            <div class="grid gap-2 text-sm sm:min-w-56 sm:text-right">
                <div>
                    <span class="block text-xs font-semibold uppercase tracking-wide text-text-muted">Rol</span>
                    <span class="font-bold text-text">{{ $accountRole }}</span>
                </div>
                <div>
                    <span class="block text-xs font-semibold uppercase tracking-wide text-text-muted">Alcance</span>
                    <span class="font-bold text-text">{{ $accountScope }}</span>
                </div>
            </div>
        </div>
    </section>

    <div class="grid gap-6 lg:grid-cols-[minmax(0,1.6fr)_minmax(18rem,0.8fr)] lg:items-start">
        <section aria-labelledby="password-form-heading" class="rounded-3xl border border-border bg-surface p-5 shadow-sm sm:p-8">
            <div class="mb-7">
                <h2 id="password-form-heading" class="text-xl font-black text-text">Definí una nueva contraseña</h2>
                <p class="mt-2 text-sm leading-6 text-text-muted">Ingresá tu contraseña actual y elegí una nueva que cumpla los requisitos.</p>
            </div>

            <form wire:submit="save" class="space-y-6">
                <div class="space-y-2">
                    <label for="current-password" class="block text-sm font-bold text-text">Contraseña actual</label>
                    <div class="relative">
                        <svg class="pointer-events-none absolute left-3.5 top-1/2 size-5 -translate-y-1/2 text-text-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                            <rect x="5" y="10" width="14" height="10" rx="2" />
                            <path stroke-linecap="round" d="M8 10V7a4 4 0 0 1 8 0v3" />
                        </svg>
                        <input
                            id="current-password"
                            x-bind:type="showCurrent ? 'text' : 'password'"
                            wire:model="current_password"
                            x-model="currentPassword"
                            autocomplete="current-password"
                            @error('current_password') aria-describedby="current-password-error" aria-invalid="true" @else aria-invalid="false" @enderror
                            class="min-h-12 w-full rounded-2xl border border-border bg-surface-muted py-3 pl-11 pr-12 text-sm text-text shadow-sm outline-none transition focus-visible:border-brand focus-visible:bg-surface focus-visible:ring-2 focus-visible:ring-brand/30"
                            required
                        >
                        <button
                            type="button"
                            x-on:click="showCurrent = ! showCurrent"
                            x-bind:aria-label="showCurrent ? 'Ocultar contraseña actual' : 'Mostrar contraseña actual'"
                            x-bind:aria-pressed="showCurrent.toString()"
                            aria-controls="current-password"
                            class="absolute right-2 top-1/2 inline-flex size-9 -translate-y-1/2 items-center justify-center rounded-xl text-text-muted transition hover:bg-surface hover:text-text focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand"
                        >
                            <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z" />
                                <circle cx="12" cy="12" r="2.5" />
                            </svg>
                        </button>
                    </div>
                    @error('current_password')
                        <p id="current-password-error" role="alert" class="text-sm font-semibold text-danger">{{ $message }}</p>
                    @enderror
                </div>

                <div class="space-y-2">
                    <label for="new-password" class="block text-sm font-bold text-text">Nueva contraseña</label>
                    <div class="relative">
                        <svg class="pointer-events-none absolute left-3.5 top-1/2 size-5 -translate-y-1/2 text-text-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 3 5 6v5c0 4.6 2.9 8.7 7 10 4.1-1.3 7-5.4 7-10V6l-7-3Z" />
                            <path stroke-linecap="round" d="M9 12h6" />
                        </svg>
                        <input
                            id="new-password"
                            x-bind:type="showNew ? 'text' : 'password'"
                            wire:model="password"
                            x-model="newPassword"
                            autocomplete="new-password"
                            aria-describedby="new-password-guidance @error('password') new-password-error @enderror"
                            @error('password') aria-invalid="true" @else aria-invalid="false" @enderror
                            class="min-h-12 w-full rounded-2xl border border-border bg-surface-muted py-3 pl-11 pr-12 text-sm text-text shadow-sm outline-none transition focus-visible:border-brand focus-visible:bg-surface focus-visible:ring-2 focus-visible:ring-brand/30"
                            required
                        >
                        <button
                            type="button"
                            x-on:click="showNew = ! showNew"
                            x-bind:aria-label="showNew ? 'Ocultar nueva contraseña' : 'Mostrar nueva contraseña'"
                            x-bind:aria-pressed="showNew.toString()"
                            aria-controls="new-password"
                            class="absolute right-2 top-1/2 inline-flex size-9 -translate-y-1/2 items-center justify-center rounded-xl text-text-muted transition hover:bg-surface hover:text-text focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand"
                        >
                            <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z" />
                                <circle cx="12" cy="12" r="2.5" />
                            </svg>
                        </button>
                    </div>
                </div>

                <div class="space-y-2">
                    <label for="new-password-confirmation" class="block text-sm font-bold text-text">Confirmar nueva contraseña</label>
                    <div class="relative">
                        <svg class="pointer-events-none absolute left-3.5 top-1/2 size-5 -translate-y-1/2 text-text-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m5 12 4 4L19 6" />
                        </svg>
                        <input
                            id="new-password-confirmation"
                            x-bind:type="showConfirmation ? 'text' : 'password'"
                            wire:model="password_confirmation"
                            x-model="confirmation"
                            autocomplete="new-password"
                            aria-describedby="new-password-guidance @error('password') new-password-error @enderror"
                            @error('password') aria-invalid="true" @else aria-invalid="false" @enderror
                            class="min-h-12 w-full rounded-2xl border border-border bg-surface-muted py-3 pl-11 pr-12 text-sm text-text shadow-sm outline-none transition focus-visible:border-brand focus-visible:bg-surface focus-visible:ring-2 focus-visible:ring-brand/30"
                            required
                        >
                        <button
                            type="button"
                            x-on:click="showConfirmation = ! showConfirmation"
                            x-bind:aria-label="showConfirmation ? 'Ocultar confirmación de contraseña' : 'Mostrar confirmación de contraseña'"
                            x-bind:aria-pressed="showConfirmation.toString()"
                            aria-controls="new-password-confirmation"
                            class="absolute right-2 top-1/2 inline-flex size-9 -translate-y-1/2 items-center justify-center rounded-xl text-text-muted transition hover:bg-surface hover:text-text focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand"
                        >
                            <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z" />
                                <circle cx="12" cy="12" r="2.5" />
                            </svg>
                        </button>
                    </div>
                    @error('password')
                        <p id="new-password-error" role="alert" class="text-sm font-semibold text-danger">{{ $message }}</p>
                    @enderror
                </div>

                <div class="flex flex-col-reverse gap-3 border-t border-border pt-6 sm:flex-row sm:items-center sm:justify-end">
                    <a href="{{ route('dashboard') }}" class="inline-flex min-h-11 items-center justify-center rounded-xl border border-border bg-surface px-5 py-2.5 text-sm font-bold text-text transition hover:bg-surface-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand focus-visible:ring-offset-2">
                        Cancelar
                    </a>
                    <x-ui.loading-button
                        type="submit"
                        target="save"
                        loading-label="Guardando contraseña..."
                        class="inline-flex min-h-11 items-center justify-center rounded-xl bg-brand px-5 py-2.5 text-sm font-bold text-white shadow-sm transition hover:bg-brand-strong focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand focus-visible:ring-offset-2 disabled:cursor-wait disabled:opacity-50"
                    >
                        Guardar contraseña
                    </x-ui.loading-button>
                </div>
            </form>
        </section>

        <aside class="space-y-5">
            <section id="new-password-guidance" aria-labelledby="requirements-heading" class="rounded-3xl border border-border bg-surface p-5 shadow-sm sm:p-6">
                <h2 id="requirements-heading" class="text-lg font-black text-text">Requisitos obligatorios</h2>
                <p class="mt-2 text-sm leading-6 text-text-muted">Estos son los requisitos que valida el servidor.</p>
                <ul class="mt-5 space-y-3 text-sm">
                    <li class="flex items-start gap-3">
                        <span class="mt-0.5 size-5 shrink-0 rounded-full border border-border" x-bind:class="hasMinimumLength ? 'bg-success border-success' : 'bg-surface-muted'" aria-hidden="true"></span>
                        <span class="flex-1 text-text">Al menos 8 caracteres</span>
                        <span class="font-semibold text-text-muted" x-text="hasMinimumLength ? 'Cumplido' : 'Pendiente'">Pendiente</span>
                    </li>
                    <li class="flex items-start gap-3">
                        <span class="mt-0.5 size-5 shrink-0 rounded-full border border-border" x-bind:class="differsFromCurrent ? 'bg-success border-success' : 'bg-surface-muted'" aria-hidden="true"></span>
                        <span class="flex-1 text-text">Diferente de la contraseña actual</span>
                        <span class="font-semibold text-text-muted" x-text="differsFromCurrent ? 'Cumplido' : 'Pendiente'">Pendiente</span>
                    </li>
                    <li class="flex items-start gap-3">
                        <span class="mt-0.5 size-5 shrink-0 rounded-full border border-border" x-bind:class="confirmationMatches ? 'bg-success border-success' : 'bg-surface-muted'" aria-hidden="true"></span>
                        <span class="flex-1 text-text">La confirmación coincide</span>
                        <span class="font-semibold text-text-muted" x-text="confirmationMatches ? 'Cumplido' : 'Pendiente'">Pendiente</span>
                    </li>
                </ul>
            </section>

            <section aria-labelledby="recommendations-heading" class="rounded-3xl border border-border bg-surface-muted p-5 sm:p-6">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <p class="text-xs font-bold uppercase tracking-wider text-warning">Opcional</p>
                        <h2 id="recommendations-heading" class="mt-1 text-lg font-black text-text">Recomendaciones</h2>
                    </div>
                    <div class="rounded-xl border border-border bg-surface px-3 py-2 text-right">
                        <span class="block text-[11px] font-semibold uppercase tracking-wide text-text-muted">Estimación de fortaleza</span>
                        <span class="text-sm font-black text-text" x-text="strengthEstimate">Sin evaluar</span>
                    </div>
                </div>
                <p class="mt-4 text-sm leading-6 text-text-muted">Para una contraseña más difícil de adivinar, considerá usar 12 o más caracteres, mayúsculas y minúsculas, números y símbolos.</p>
            </section>

            <section aria-labelledby="security-note-heading" class="rounded-3xl border border-success/30 bg-success/10 p-5 sm:p-6">
                <div class="flex gap-3">
                    <svg class="mt-0.5 size-5 shrink-0 text-success" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 3 5 6v5c0 4.6 2.9 8.7 7 10 4.1-1.3 7-5.4 7-10V6l-7-3Z" />
                    </svg>
                    <div>
                        <h2 id="security-note-heading" class="font-black text-text">Protección al guardar</h2>
                        <p class="mt-2 text-sm leading-6 text-text-muted">La aplicación almacena la nueva contraseña mediante un hash seguro e invalida las demás sesiones guardadas de tu cuenta.</p>
                    </div>
                </div>
            </section>
        </aside>
    </div>
</div>
