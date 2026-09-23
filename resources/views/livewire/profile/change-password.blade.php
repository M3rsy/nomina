<div class="mx-auto max-w-2xl space-y-6 py-8">
    <x-ui.page-header
        title="Cambiar contraseña"
        description="Actualizá la contraseña de acceso de tu cuenta."
    />

    @if (session('status'))
        <x-ui.alert id="change-password-status" variant="success">
            {{ session('status') }}
        </x-ui.alert>
    @endif

    <x-ui.card>
        <form wire:submit="save" class="space-y-5">
            <x-ui.input
                id="current-password"
                label="Contraseña actual"
                type="password"
                wire:model="current_password"
                autocomplete="current-password"
                :error="$errors->first('current_password')"
                required
            />

            <x-ui.input
                id="new-password"
                label="Nueva contraseña"
                type="password"
                wire:model="password"
                autocomplete="new-password"
                hint="Use al menos 8 caracteres."
                :error="$errors->first('password')"
                required
            />

            <div class="space-y-1.5">
                <label for="new-password-confirmation" class="block text-sm font-semibold text-text">Confirmar nueva contraseña</label>
                <input
                    id="new-password-confirmation"
                    type="password"
                    wire:model="password_confirmation"
                    autocomplete="new-password"
                    @error('password') aria-describedby="new-password-error" aria-invalid="true" @else aria-invalid="false" @enderror
                    class="min-h-11 w-full rounded-xl border border-border bg-surface px-3 py-2 text-sm text-text shadow-sm outline-none transition focus-visible:border-brand focus-visible:ring-2 focus-visible:ring-brand/30 disabled:cursor-not-allowed disabled:bg-surface-muted disabled:text-text-muted"
                    required
                >
            </div>

            <x-ui.loading-button
                type="submit"
                target="save"
                loading-label="Guardando contraseña..."
                class="inline-flex min-h-11 items-center justify-center rounded-xl bg-brand px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-brand-strong focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand focus-visible:ring-offset-2 disabled:cursor-wait disabled:opacity-50"
            >
                Guardar contraseña
            </x-ui.loading-button>
        </form>
    </x-ui.card>
</div>
