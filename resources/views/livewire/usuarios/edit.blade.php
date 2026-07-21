<div class="min-h-screen bg-[radial-gradient(circle_at_top,_#f8fafc_0%,_#f8f1ff_38%,_#ffffff_80%)] px-4 py-8 sm:px-6 lg:px-8">
    <div class="mx-auto max-w-3xl space-y-5">
        <header class="rounded-3xl border border-slate-200/70 bg-white/90 p-5 shadow-sm backdrop-blur sm:p-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="inline-flex items-center rounded-full bg-indigo-50 px-3 py-1 text-xs font-semibold uppercase tracking-[0.16em] text-indigo-600">
                        Usuarios
                    </p>
                    <h1 class="mt-3 text-2xl font-black tracking-tight text-slate-950 sm:text-3xl">Editar usuario</h1>
                    <p class="mt-2 max-w-2xl text-sm text-slate-600">Modificá datos de acceso y permisos conservando el control de cuentas existentes.</p>
                </div>
                <a
                    href="/usuarios"
                    class="inline-flex min-h-11 shrink-0 items-center rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2"
                >
                    Cancelar
                </a>
            </div>
        </header>

        <form wire:submit="save" class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
            <div class="space-y-4">
                <div>
                    <label for="name" class="block text-sm font-medium text-slate-700">Nombre</label>
                    <input
                        id="name"
                        type="text"
                        wire:model="name"
                        required
                        class="mt-1 h-11 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm"
                    >
                    @error('name') <span class="mt-1 block text-sm text-rose-700">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label for="email" class="block text-sm font-medium text-slate-700">Correo electrónico</label>
                    <input
                        id="email"
                        type="email"
                        wire:model="email"
                        required
                        class="mt-1 h-11 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm"
                    >
                    @error('email') <span class="mt-1 block text-sm text-rose-700">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label for="password" class="block text-sm font-medium text-slate-700">Nueva contraseña (dejar en blanco para no cambiar)</label>
                    <input
                        id="password"
                        type="password"
                        wire:model="password"
                        class="mt-1 h-11 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm"
                    >
                    @error('password') <span class="mt-1 block text-sm text-rose-700">{{ $message }}</span> @enderror
                </div>

                @if ($isSuperAdmin)
                    <div>
                        <label for="role" class="block text-sm font-medium text-slate-700">Rol</label>
                        <select id="role" wire:model="role" class="mt-1 h-11 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm">
                            <option value="super_admin">Super administrador</option>
                            <option value="company_admin">Administrador de empresa</option>
                        </select>
                        @error('role') <span class="mt-1 block text-sm text-rose-700">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <label for="company_id" class="block text-sm font-medium text-slate-700">Empresa</label>
                        <select id="company_id" wire:model="company_id" class="mt-1 h-11 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm">
                            <option value="">Ninguna</option>
                            @foreach ($companies as $company)
                                <option value="{{ $company->id }}">{{ $company->name }}</option>
                            @endforeach
                        </select>
                        @error('company_id') <span class="mt-1 block text-sm text-rose-700">{{ $message }}</span> @enderror
                    </div>
                @endif
            </div>

            <div class="mt-6 flex items-center justify-end gap-3">
                <button
                    type="submit"
                    class="inline-flex min-h-11 items-center rounded-xl bg-slate-900 px-6 py-2 text-sm font-semibold text-white transition hover:bg-slate-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2"
                >
                    Guardar
                </button>
            </div>
        </form>
    </div>
</div>
