<x-auth.shell
    eyebrow="Recuperación de acceso"
    heading="Recuperar contraseña"
    description="Ingrese su correo para recibir un enlace seguro de restablecimiento."
>
    @if ($status)
        <div id="forgot-status" role="status" aria-live="polite" class="mb-5 border-l-4 border-emerald-500 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
            {{ $status }}
        </div>
    @endif

    <form wire:submit="sendResetLink" class="space-y-5">
        <div>
            <label for="forgot-email" class="block text-sm font-semibold text-slate-800">Correo electrónico</label>
            <input
                id="forgot-email"
                type="email"
                wire:model="email"
                autocomplete="email"
                inputmode="email"
                @error('email') aria-describedby="forgot-email-error" aria-invalid="true" @else aria-invalid="false" @enderror
                class="mt-2 block min-h-12 w-full rounded-xl border border-slate-300 bg-white px-4 text-slate-950 shadow-sm outline-none transition placeholder:text-slate-400 focus:border-indigo-600 focus:ring-4 focus:ring-indigo-100 motion-reduce:transition-none"
                required
                autofocus
            >
            @error('email')
                <p id="forgot-email-error" role="alert" class="mt-2 text-sm font-medium text-red-700">{{ $message }}</p>
            @enderror
        </div>

        <button
            type="submit"
            wire:loading.attr="disabled"
            wire:target="sendResetLink"
            class="min-h-12 w-full rounded-xl bg-indigo-600 px-4 py-3 text-sm font-bold text-white shadow-sm transition hover:bg-indigo-700 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-indigo-200 disabled:cursor-wait disabled:bg-indigo-400 motion-reduce:transition-none"
        >
            <span wire:loading.remove wire:target="sendResetLink">Enviar enlace de recuperación</span>
            <span wire:loading wire:target="sendResetLink">Enviando enlace...</span>
        </button>
        <p role="status" aria-live="polite" class="sr-only">
            <span wire:loading wire:target="sendResetLink">Enviando enlace...</span>
        </p>
    </form>

    <div class="mt-5 text-center">
        <a href="{{ route('login') }}" class="inline-flex min-h-11 items-center rounded-lg px-2 text-sm font-semibold text-indigo-700 underline-offset-4 hover:underline focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-indigo-600 focus-visible:ring-offset-2">
            Volver al inicio de sesión
        </a>
    </div>
</x-auth.shell>
