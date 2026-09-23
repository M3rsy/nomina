<x-auth.shell
    heading="Iniciar sesión"
    description="Acceda al control de asistencia y procesamiento de nómina."
>
    @if (session('error'))
        <x-ui.feedback id="login-status" type="error">
            {{ session('error') }}
        </x-ui.feedback>
    @endif

    <form wire:submit="login" class="space-y-5">
        <x-ui.form-field
            id="login-email"
            label="Correo electrónico"
            type="email"
            :error="$errors->first('email')"
            wire:model="email"
            autocomplete="username"
            inputmode="email"
            required
            autofocus
        />

        <x-ui.password-field
            id="login-password"
            label="Contraseña"
            :error="$errors->first('password')"
            wire:model="password"
            autocomplete="current-password"
            required
        />

        <x-ui.loading-button
            type="submit"
            target="login"
            loading-label="Ingresando..."
            class="min-h-12 w-full rounded-xl bg-indigo-600 px-4 py-3 text-sm font-bold text-white shadow-sm transition hover:bg-indigo-700 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-indigo-200 disabled:cursor-wait disabled:bg-indigo-400 motion-reduce:transition-none"
        >
            Ingresar
        </x-ui.loading-button>
    </form>

    <div class="mt-5 text-center">
        <a href="{{ route('password.request') }}" class="inline-flex min-h-11 items-center rounded-lg px-2 text-sm font-semibold text-indigo-700 underline-offset-4 hover:underline focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-indigo-600 focus-visible:ring-offset-2">
            ¿Olvidó su contraseña?
        </a>
    </div>
</x-auth.shell>
