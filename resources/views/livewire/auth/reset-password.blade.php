<x-auth.shell
    eyebrow="Recuperación de acceso"
    heading="Restablecer contraseña"
    description="Defina una nueva contraseña para recuperar el acceso a su cuenta."
>
    <form wire:submit="resetPassword" class="space-y-5">
        <input type="hidden" wire:model="token">

        <div>
            <label for="reset-email" class="block text-sm font-semibold text-slate-800">Correo electrónico</label>
            <input
                id="reset-email"
                type="email"
                wire:model="email"
                autocomplete="email"
                inputmode="email"
                @error('email') aria-describedby="reset-email-error" aria-invalid="true" @else aria-invalid="false" @enderror
                class="mt-2 block min-h-12 w-full rounded-xl border border-slate-300 bg-white px-4 text-slate-950 shadow-sm outline-none transition placeholder:text-slate-400 focus:border-indigo-600 focus:ring-4 focus:ring-indigo-100 motion-reduce:transition-none"
                required
                autofocus
            >
            @error('email')
                <p id="reset-email-error" role="alert" class="mt-2 text-sm font-medium text-red-700">{{ $message }}</p>
            @enderror
        </div>

        <div x-data="{ showPassword: false }">
            <label for="reset-password" class="block text-sm font-semibold text-slate-800">Nueva contraseña</label>
            <div class="relative mt-2">
                <input
                    id="reset-password"
                    type="password"
                    x-bind:type="showPassword ? 'text' : 'password'"
                    wire:model="password"
                    autocomplete="new-password"
                    aria-describedby="reset-password-hint @error('password') reset-password-error @enderror"
                    @error('password') aria-invalid="true" @else aria-invalid="false" @enderror
                    class="block min-h-12 w-full rounded-xl border border-slate-300 bg-white px-4 pr-24 text-slate-950 shadow-sm outline-none transition focus:border-indigo-600 focus:ring-4 focus:ring-indigo-100 motion-reduce:transition-none"
                    required
                >
                <button
                    type="button"
                    x-on:click="showPassword = ! showPassword"
                    x-bind:aria-label="showPassword ? 'Ocultar nueva contraseña' : 'Mostrar nueva contraseña'"
                    x-bind:aria-pressed="showPassword"
                    aria-controls="reset-password"
                    class="absolute inset-y-0 right-1 my-1 min-h-10 rounded-lg px-3 text-sm font-bold text-indigo-700 transition hover:bg-indigo-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-600 motion-reduce:transition-none"
                >
                    <span x-text="showPassword ? 'Ocultar' : 'Mostrar'">Mostrar</span>
                </button>
            </div>
            <p id="reset-password-hint" class="mt-2 text-xs text-slate-500">Use al menos 8 caracteres.</p>
        </div>

        <div x-data="{ showPassword: false }">
            <label for="reset-password-confirmation" class="block text-sm font-semibold text-slate-800">Confirmar contraseña</label>
            <div class="relative mt-2">
                <input
                    id="reset-password-confirmation"
                    type="password"
                    x-bind:type="showPassword ? 'text' : 'password'"
                    wire:model="password_confirmation"
                    autocomplete="new-password"
                    @error('password') aria-describedby="reset-password-error" aria-invalid="true" @else aria-invalid="false" @enderror
                    class="block min-h-12 w-full rounded-xl border border-slate-300 bg-white px-4 pr-24 text-slate-950 shadow-sm outline-none transition focus:border-indigo-600 focus:ring-4 focus:ring-indigo-100 motion-reduce:transition-none"
                    required
                >
                <button
                    type="button"
                    x-on:click="showPassword = ! showPassword"
                    x-bind:aria-label="showPassword ? 'Ocultar confirmación de contraseña' : 'Mostrar confirmación de contraseña'"
                    x-bind:aria-pressed="showPassword"
                    aria-controls="reset-password-confirmation"
                    class="absolute inset-y-0 right-1 my-1 min-h-10 rounded-lg px-3 text-sm font-bold text-indigo-700 transition hover:bg-indigo-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-600 motion-reduce:transition-none"
                >
                    <span x-text="showPassword ? 'Ocultar' : 'Mostrar'">Mostrar</span>
                </button>
            </div>
        </div>

        @error('password')
            <p id="reset-password-error" role="alert" class="text-sm font-medium text-red-700">{{ $message }}</p>
        @enderror

        <button
            type="submit"
            wire:loading.attr="disabled"
            wire:target="resetPassword"
            class="min-h-12 w-full rounded-xl bg-indigo-600 px-4 py-3 text-sm font-bold text-white shadow-sm transition hover:bg-indigo-700 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-indigo-200 disabled:cursor-wait disabled:bg-indigo-400 motion-reduce:transition-none"
        >
            <span wire:loading.remove wire:target="resetPassword">Restablecer contraseña</span>
            <span wire:loading wire:target="resetPassword">Restableciendo...</span>
        </button>
        <p role="status" aria-live="polite" class="sr-only">
            <span wire:loading wire:target="resetPassword">Restableciendo...</span>
        </p>
    </form>

    <div class="mt-5 text-center">
        <a href="{{ route('login') }}" class="inline-flex min-h-11 items-center rounded-lg px-2 text-sm font-semibold text-indigo-700 underline-offset-4 hover:underline focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-indigo-600 focus-visible:ring-offset-2">
            Volver al inicio de sesión
        </a>
    </div>
</x-auth.shell>
