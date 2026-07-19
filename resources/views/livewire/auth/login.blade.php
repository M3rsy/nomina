<x-auth.shell
    heading="Iniciar sesión"
    description="Acceda al control de asistencia y procesamiento de nómina."
>
    @if (session('error'))
        <div id="login-status" role="alert" aria-live="assertive" class="mb-5 border-l-4 border-red-500 bg-red-50 px-4 py-3 text-sm text-red-800">
            {{ session('error') }}
        </div>
    @endif

    <form wire:submit="login" class="space-y-5">
        <div>
            <label for="login-email" class="block text-sm font-semibold text-slate-800">Correo electrónico</label>
            <input
                id="login-email"
                type="email"
                wire:model="email"
                autocomplete="username"
                inputmode="email"
                @error('email') aria-describedby="login-email-error" aria-invalid="true" @else aria-invalid="false" @enderror
                class="mt-2 block min-h-12 w-full rounded-xl border border-slate-300 bg-white px-4 text-slate-950 shadow-sm outline-none transition placeholder:text-slate-400 focus:border-indigo-600 focus:ring-4 focus:ring-indigo-100 motion-reduce:transition-none"
                required
                autofocus
            >
            @error('email')
                <p id="login-email-error" role="alert" class="mt-2 text-sm font-medium text-red-700">{{ $message }}</p>
            @enderror
        </div>

        <div x-data="{ showPassword: false }">
            <label for="login-password" class="block text-sm font-semibold text-slate-800">Contraseña</label>
            <div class="relative mt-2">
                <input
                    id="login-password"
                    type="password"
                    x-bind:type="showPassword ? 'text' : 'password'"
                    wire:model="password"
                    autocomplete="current-password"
                    @error('password') aria-describedby="login-password-error" aria-invalid="true" @else aria-invalid="false" @enderror
                    class="block min-h-12 w-full rounded-xl border border-slate-300 bg-white px-4 pr-24 text-slate-950 shadow-sm outline-none transition focus:border-indigo-600 focus:ring-4 focus:ring-indigo-100 motion-reduce:transition-none"
                    required
                >
                <button
                    type="button"
                    x-on:click="showPassword = ! showPassword"
                    x-bind:aria-label="showPassword ? 'Ocultar contraseña' : 'Mostrar contraseña'"
                    x-bind:aria-pressed="showPassword"
                    aria-controls="login-password"
                    class="absolute inset-y-0 right-1 my-1 min-h-10 rounded-lg px-3 text-sm font-bold text-indigo-700 transition hover:bg-indigo-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-600 motion-reduce:transition-none"
                >
                    <span x-text="showPassword ? 'Ocultar' : 'Mostrar'">Mostrar</span>
                </button>
            </div>
            @error('password')
                <p id="login-password-error" role="alert" class="mt-2 text-sm font-medium text-red-700">{{ $message }}</p>
            @enderror
        </div>

        <button
            type="submit"
            wire:loading.attr="disabled"
            wire:target="login"
            class="min-h-12 w-full rounded-xl bg-indigo-600 px-4 py-3 text-sm font-bold text-white shadow-sm transition hover:bg-indigo-700 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-indigo-200 disabled:cursor-wait disabled:bg-indigo-400 motion-reduce:transition-none"
        >
            <span wire:loading.remove wire:target="login">Ingresar</span>
            <span wire:loading wire:target="login">Ingresando...</span>
        </button>
        <p role="status" aria-live="polite" class="sr-only">
            <span wire:loading wire:target="login">Ingresando...</span>
        </p>
    </form>

    <div class="mt-5 text-center">
        <a href="{{ route('password.request') }}" class="inline-flex min-h-11 items-center rounded-lg px-2 text-sm font-semibold text-indigo-700 underline-offset-4 hover:underline focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-indigo-600 focus-visible:ring-offset-2">
            ¿Olvidó su contraseña?
        </a>
    </div>
</x-auth.shell>
