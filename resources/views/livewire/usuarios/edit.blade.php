<div class="min-h-screen bg-[radial-gradient(circle_at_top,_#eef4ff_0%,_#f8fafc_42%,_#ffffff_82%)] px-4 py-8 sm:px-6 lg:px-8" data-users-form="edit">
    <div class="mx-auto max-w-7xl space-y-6">
        <header class="flex flex-col gap-4 rounded-3xl border border-slate-200/80 bg-white/95 p-5 shadow-sm backdrop-blur sm:p-6 lg:flex-row lg:items-start lg:justify-between">
            <div class="max-w-3xl space-y-3">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="inline-flex items-center rounded-full bg-indigo-50 px-3 py-1 text-xs font-bold uppercase tracking-[0.16em] text-indigo-700">
                        Gestión de Usuarios / Control de Acceso
                    </span>
                    <span class="inline-flex items-center gap-1.5 rounded-full {{ $is_active ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700' }} px-3 py-1 text-xs font-bold uppercase tracking-[0.12em]">
                        <span class="h-1.5 w-1.5 rounded-full {{ $is_active ? 'bg-emerald-500' : 'bg-rose-500' }}"></span>
                        {{ $is_active ? 'Cuenta activa' : 'Cuenta inactiva' }}
                    </span>
                </div>
                <div>
                    <h1 class="text-2xl font-black tracking-tight text-slate-950 sm:text-3xl">Editar Usuario</h1>
                    <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-600">
                        Actualizá datos de acceso, rol y estado respetando las reglas de recuperación y revocación de sesiones existentes.
                    </p>
                </div>
            </div>

            <a
                href="{{ route('usuarios.index') }}"
                class="inline-flex min-h-11 items-center justify-center rounded-2xl border border-slate-300 bg-white px-4 py-2 text-sm font-bold text-slate-700 shadow-sm transition hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2"
            >
                Volver a Usuarios
            </a>
        </header>

        <form wire:submit="save" class="grid grid-cols-1 gap-6 lg:grid-cols-12">
            <div class="space-y-6 lg:col-span-7">
                <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
                    <div class="border-b border-slate-200 pb-4">
                        <p class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">Cuenta existente</p>
                        <h2 class="mt-2 text-lg font-black text-slate-950">Datos de identidad y acceso</h2>
                        <p class="mt-1 text-sm text-slate-600">Cambios básicos de identificación y credenciales.</p>
                    </div>

                    <div class="mt-5 grid grid-cols-1 gap-4">
                        <div>
                            <label for="name" class="block text-sm font-bold text-slate-700">Nombre completo <span class="text-rose-600">*</span></label>
                            <input
                                id="name"
                                type="text"
                                wire:model="name"
                                required
                                class="mt-1 h-11 w-full rounded-2xl border border-slate-300 bg-slate-50 px-4 text-sm text-slate-900 outline-none transition focus:border-indigo-500 focus:bg-white focus:ring-2 focus:ring-indigo-100"
                            >
                            @error('name') <span class="mt-1 block text-sm text-rose-700">{{ $message }}</span> @enderror
                        </div>

                        <div>
                            <label for="email" class="block text-sm font-bold text-slate-700">Correo electrónico <span class="text-rose-600">*</span></label>
                            <input
                                id="email"
                                type="email"
                                wire:model="email"
                                required
                                class="mt-1 h-11 w-full rounded-2xl border border-slate-300 bg-slate-50 px-4 text-sm text-slate-900 outline-none transition focus:border-indigo-500 focus:bg-white focus:ring-2 focus:ring-indigo-100"
                            >
                            @error('email') <span class="mt-1 block text-sm text-rose-700">{{ $message }}</span> @enderror
                        </div>

                        <div>
                            <label for="password" class="block text-sm font-bold text-slate-700">Nueva contraseña opcional</label>
                            <input
                                id="password"
                                type="password"
                                wire:model="password"
                                class="mt-1 h-11 w-full rounded-2xl border border-slate-300 bg-slate-50 px-4 text-sm text-slate-900 outline-none transition focus:border-indigo-500 focus:bg-white focus:ring-2 focus:ring-indigo-100"
                            >
                            <p class="mt-1 text-xs text-slate-500">Dejala vacía para conservar la contraseña actual.</p>
                            @error('password') <span class="mt-1 block text-sm text-rose-700">{{ $message }}</span> @enderror
                        </div>
                    </div>
                </section>
            </div>

            <aside class="space-y-6 lg:col-span-5">
                @if ($isSuperAdmin)
                    <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
                        <div class="border-b border-slate-200 pb-4">
                            <p class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">Alcance corporativo</p>
                            <h2 class="mt-2 text-lg font-black text-slate-950">Rol y empresa asignada</h2>
                            <p class="mt-1 text-sm text-slate-600">Los cambios de rol, empresa o estado revocan sesiones cuando corresponde.</p>
                        </div>

                        <div class="mt-5 space-y-4">
                            <div>
                                <label for="role" class="block text-sm font-bold text-slate-700">Rol</label>
                                <select id="role" wire:model="role" class="mt-1 h-11 w-full rounded-2xl border border-slate-300 bg-slate-50 px-4 text-sm text-slate-900 outline-none transition focus:border-indigo-500 focus:bg-white focus:ring-2 focus:ring-indigo-100">
                                    <option value="super_admin">Super administrador</option>
                                    <option value="company_admin">Administrador de empresa</option>
                                </select>
                                @error('role') <span class="mt-1 block text-sm text-rose-700">{{ $message }}</span> @enderror
                            </div>

                            <div>
                                <label for="company_id" class="block text-sm font-bold text-slate-700">Empresa</label>
                                <select id="company_id" wire:model="company_id" class="mt-1 h-11 w-full rounded-2xl border border-slate-300 bg-slate-50 px-4 text-sm text-slate-900 outline-none transition focus:border-indigo-500 focus:bg-white focus:ring-2 focus:ring-indigo-100">
                                    <option value="">Ninguna</option>
                                    @foreach ($companies as $company)
                                        <option value="{{ $company->id }}">{{ $company->name }}</option>
                                    @endforeach
                                </select>
                                @error('company_id') <span class="mt-1 block text-sm text-rose-700">{{ $message }}</span> @enderror
                            </div>

                            <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                <label for="is_active" class="flex items-start gap-3">
                                    <input id="is_active" type="checkbox" wire:model="is_active" class="mt-1 h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                                    <span>
                                        <span class="block text-sm font-bold text-slate-800">Estado y recuperación de cuenta</span>
                                        <span class="mt-1 block text-sm leading-6 text-slate-600">Activá esta opción para mantener o recuperar el acceso cuando la cuenta cumpla las reglas de empresa y rol.</span>
                                    </span>
                                </label>
                                @error('is_active') <span class="mt-2 block text-sm text-rose-700">{{ $message }}</span> @enderror
                            </div>
                        </div>
                    </section>
                @endif

                <section class="rounded-3xl border border-indigo-100 bg-indigo-50/70 p-5 text-sm text-indigo-950 shadow-sm">
                    <h2 class="font-bold">Resumen de cuenta</h2>
                    <dl class="mt-3 space-y-2 text-sm">
                        <div class="flex justify-between gap-4">
                            <dt class="text-indigo-900/70">Usuario</dt>
                            <dd class="font-semibold text-indigo-950">{{ $user->name }}</dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="text-indigo-900/70">Correo</dt>
                            <dd class="font-semibold text-indigo-950">{{ $user->email }}</dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="text-indigo-900/70">Estado actual</dt>
                            <dd class="font-semibold text-indigo-950">{{ $user->is_active ? 'Activo' : 'Inactivo' }}</dd>
                        </div>
                    </dl>
                </section>
            </aside>

            <div class="lg:col-span-12">
                <div class="sticky bottom-4 z-20 flex flex-col gap-3 rounded-3xl border border-slate-200 bg-white/95 p-4 shadow-xl backdrop-blur sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <p class="text-sm font-bold text-slate-950">Actualización de usuario</p>
                        <p class="text-sm text-slate-600">La lógica de validación, recuperación y revocación de sesiones se mantiene en Livewire.</p>
                    </div>
                    <div class="flex items-center justify-end gap-3">
                        <a href="{{ route('usuarios.index') }}" class="inline-flex min-h-11 items-center rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-bold text-slate-700 transition hover:bg-slate-50">Cancelar</a>
                        <x-ui.loading-button type="submit" target="save" loading-label="Guardando…" class="inline-flex min-h-11 items-center rounded-xl bg-indigo-600 px-6 py-2 text-sm font-bold text-white transition hover:bg-indigo-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2">
                            Guardar cambios
                        </x-ui.loading-button>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>
