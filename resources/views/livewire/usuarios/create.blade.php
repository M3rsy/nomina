<div class="min-h-screen bg-[radial-gradient(circle_at_top,_#eef4ff_0%,_#f8fafc_42%,_#ffffff_82%)] px-4 py-8 sm:px-6 lg:px-8" data-users-form="create">
    <div class="mx-auto max-w-7xl space-y-6">
        <header class="flex flex-col gap-4 rounded-3xl border border-slate-200/80 bg-white/95 p-5 shadow-sm backdrop-blur sm:p-6 lg:flex-row lg:items-start lg:justify-between">
            <div class="max-w-3xl space-y-3">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="inline-flex items-center rounded-full bg-indigo-50 px-3 py-1 text-xs font-bold uppercase tracking-[0.16em] text-indigo-700">
                        Gestión de Usuarios / Control de Acceso
                    </span>
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-3 py-1 text-xs font-bold uppercase tracking-[0.12em] text-emerald-700">
                        <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
                        RBAC activo
                    </span>
                </div>
                <div>
                    <h1 class="text-2xl font-black tracking-tight text-slate-950 sm:text-3xl">Crear Nuevo Usuario</h1>
                    <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-600">
                        Configurá los datos de acceso, rol y alcance corporativo soportados por el módulo actual.
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
                        <p class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">Paso 1 de 2</p>
                        <h2 class="mt-2 text-lg font-black text-slate-950">Datos de identidad y acceso</h2>
                        <p class="mt-1 text-sm text-slate-600">Información mínima necesaria para crear una cuenta operativa.</p>
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
                            <label for="password" class="block text-sm font-bold text-slate-700">Contraseña inicial <span class="text-rose-600">*</span></label>
                            <input
                                id="password"
                                type="password"
                                wire:model="password"
                                required
                                class="mt-1 h-11 w-full rounded-2xl border border-slate-300 bg-slate-50 px-4 text-sm text-slate-900 outline-none transition focus:border-indigo-500 focus:bg-white focus:ring-2 focus:ring-indigo-100"
                            >
                            <p class="mt-1 text-xs text-slate-500">Debe tener al menos 8 caracteres. La contraseña se guarda hasheada.</p>
                            @error('password') <span class="mt-1 block text-sm text-rose-700">{{ $message }}</span> @enderror
                        </div>
                    </div>
                </section>
            </div>

            <aside class="space-y-6 lg:col-span-5">
                <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
                    <div class="border-b border-slate-200 pb-4">
                        <p class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">Paso 2 de 2</p>
                        <h2 class="mt-2 text-lg font-black text-slate-950">Rol y empresa asignada</h2>
                        <p class="mt-1 text-sm text-slate-600">El backend valida qué roles y empresas puede asignar el actor actual.</p>
                    </div>

                    <div class="mt-5 space-y-4">
                        <div>
                            <label for="role" class="block text-sm font-bold text-slate-700">Rol</label>
                            <select id="role" wire:model="role" class="mt-1 h-11 w-full rounded-2xl border border-slate-300 bg-slate-50 px-4 text-sm text-slate-900 outline-none transition focus:border-indigo-500 focus:bg-white focus:ring-2 focus:ring-indigo-100">
                                @if ($isSuperAdmin)
                                    <option value="super_admin">Super administrador</option>
                                @endif
                                <option value="company_admin">Administrador de empresa</option>
                            </select>
                            @error('role') <span class="mt-1 block text-sm text-rose-700">{{ $message }}</span> @enderror
                        </div>

                        @if ($isSuperAdmin)
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
                        @endif
                    </div>
                </section>

                <section class="rounded-3xl border border-indigo-100 bg-indigo-50/70 p-5 text-sm text-indigo-950 shadow-sm">
                    <h2 class="font-bold">Matriz resultante de permisos</h2>
                    <p class="mt-1 leading-6 text-indigo-900/80">La cuenta se crea con el rol seleccionado y queda sujeta a las policies existentes.</p>
                </section>
            </aside>

            <div class="lg:col-span-12">
                <div class="sticky bottom-4 z-20 flex flex-col gap-3 rounded-3xl border border-slate-200 bg-white/95 p-4 shadow-xl backdrop-blur sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <p class="text-sm font-bold text-slate-950">Alta de usuario</p>
                        <p class="text-sm text-slate-600">Se guardará una cuenta activa con los datos ingresados.</p>
                    </div>
                    <div class="flex items-center justify-end gap-3">
                        <a href="{{ route('usuarios.index') }}" class="inline-flex min-h-11 items-center rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-bold text-slate-700 transition hover:bg-slate-50">Cancelar</a>
                        <x-ui.loading-button type="submit" target="save" loading-label="Guardando…" class="inline-flex min-h-11 items-center rounded-xl bg-indigo-600 px-6 py-2 text-sm font-bold text-white transition hover:bg-indigo-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2">
                            Crear usuario
                        </x-ui.loading-button>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>
