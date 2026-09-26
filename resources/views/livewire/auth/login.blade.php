<x-auth.shell
    eyebrow="Acceso seguro"
    heading="Iniciar sesión"
    description="Accedé al control de asistencia y procesamiento de nómina."
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
            icon="mail"
            placeholder="nombre@empresa.com"
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
            placeholder="Ingresá tu contraseña"
            :error="$errors->first('password')"
            wire:model="password"
            autocomplete="current-password"
            required
        />

        <div class="flex justify-end">
            <a href="{{ route('password.request') }}" class="inline-flex min-h-10 items-center rounded-lg px-1 text-sm font-semibold text-indigo-700 underline-offset-4 transition hover:text-indigo-900 hover:underline focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-indigo-200 motion-reduce:transition-none">
                ¿Olvidaste tu contraseña?
            </a>
        </div>

        <x-ui.loading-button
            type="submit"
            target="login"
            loading-label="Ingresando..."
            class="min-h-12 w-full rounded-xl bg-indigo-600 px-4 py-3 text-sm font-bold text-white shadow-lg shadow-indigo-600/20 transition hover:bg-indigo-700 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-indigo-200 disabled:cursor-wait disabled:bg-indigo-400 motion-reduce:transition-none"
        >
            Ingresar al Sistema
        </x-ui.loading-button>
    </form>

    <div class="my-6 flex items-center gap-3" aria-hidden="true">
        <span class="h-px flex-1 bg-slate-200"></span>
        <span class="text-[10px] font-bold uppercase tracking-[0.16em] text-slate-400">Acceso alternativo</span>
        <span class="h-px flex-1 bg-slate-200"></span>
    </div>

    <button
        type="button"
        disabled
        title="Disponible próximamente"
        class="flex min-h-12 w-full cursor-not-allowed items-center justify-center gap-3 rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm font-semibold text-slate-500 shadow-sm"
    >
        <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 20.25h15M6.75 20.25V8.5m10.5 11.75V8.5M4.5 8.5h15L12 3.75 4.5 8.5Zm5.25 0v11.75m4.5-11.75v11.75" />
        </svg>
        Continuar con SSO corporativo
    </button>

    <p class="mt-5 text-center text-xs leading-5 text-slate-500">
        Acceso exclusivo para personal autorizado.
    </p>
</x-auth.shell>
