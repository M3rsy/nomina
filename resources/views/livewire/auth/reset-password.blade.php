<x-auth.shell
    eyebrow="Recuperación de acceso"
    heading="Restablecer contraseña"
    description="Defina una nueva contraseña para recuperar el acceso a su cuenta."
>
    <form wire:submit="resetPassword" class="space-y-5">
        <input type="hidden" wire:model="token">

        <x-ui.form-field
            id="reset-email"
            label="Correo electrónico"
            type="email"
            :error="$errors->first('email')"
            wire:model="email"
            autocomplete="email"
            inputmode="email"
            required
            autofocus
        />

        <x-ui.password-field
            id="reset-password"
            label="Nueva contraseña"
            hint="Use al menos 8 caracteres."
            :error="$errors->first('password')"
            show-label="Mostrar nueva contraseña"
            hide-label="Ocultar nueva contraseña"
            wire:model="password"
            autocomplete="new-password"
            required
        />

        <x-ui.password-field
            id="reset-password-confirmation"
            label="Confirmar contraseña"
            :described-by="$errors->has('password') ? 'reset-password-error' : null"
            :invalid="$errors->has('password')"
            show-label="Mostrar confirmación de contraseña"
            hide-label="Ocultar confirmación de contraseña"
            wire:model="password_confirmation"
            autocomplete="new-password"
            required
        />

        <x-ui.loading-button
            type="submit"
            target="resetPassword"
            loading-label="Restableciendo..."
            class="min-h-12 w-full rounded-xl bg-indigo-600 px-4 py-3 text-sm font-bold text-white shadow-sm transition hover:bg-indigo-700 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-indigo-200 disabled:cursor-wait disabled:bg-indigo-400 motion-reduce:transition-none"
        >
            Restablecer contraseña
        </x-ui.loading-button>
    </form>

    <div class="mt-5 text-center">
        <a href="{{ route('login') }}" class="inline-flex min-h-11 items-center rounded-lg px-2 text-sm font-semibold text-indigo-700 underline-offset-4 hover:underline focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-indigo-600 focus-visible:ring-offset-2">
            Volver al inicio de sesión
        </a>
    </div>
</x-auth.shell>
