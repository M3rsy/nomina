<x-auth.shell
    eyebrow="Recuperación de acceso"
    heading="Recuperar contraseña"
    description="Ingrese su correo para recibir un enlace seguro de restablecimiento."
>
    @if ($status)
        <x-ui.feedback id="forgot-status" type="success">
            {{ $status }}
        </x-ui.feedback>
    @endif

    <form wire:submit="sendResetLink" class="space-y-5">
        <x-ui.form-field
            id="forgot-email"
            label="Correo electrónico"
            type="email"
            :error="$errors->first('email')"
            wire:model="email"
            autocomplete="email"
            inputmode="email"
            required
            autofocus
        />

        <x-ui.loading-button
            type="submit"
            target="sendResetLink"
            loading-label="Enviando enlace..."
            class="min-h-12 w-full rounded-xl bg-indigo-600 px-4 py-3 text-sm font-bold text-white shadow-sm transition hover:bg-indigo-700 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-indigo-200 disabled:cursor-wait disabled:bg-indigo-400 motion-reduce:transition-none"
        >
            Enviar enlace de recuperación
        </x-ui.loading-button>
    </form>

    <div class="mt-5 text-center">
        <a href="{{ route('login') }}" class="inline-flex min-h-11 items-center rounded-lg px-2 text-sm font-semibold text-indigo-700 underline-offset-4 hover:underline focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-indigo-600 focus-visible:ring-offset-2">
            Volver al inicio de sesión
        </a>
    </div>
</x-auth.shell>
