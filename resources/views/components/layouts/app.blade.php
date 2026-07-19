<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ config('app.name', 'Nomina') }}</title>

    @livewireStyles
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-gray-50 text-gray-900 antialiased">
    <div class="min-h-screen">
        @auth
            <nav class="bg-white border-b border-gray-200">
                <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                    <div class="flex justify-between h-16">
                        <div class="flex items-center space-x-4">
                            <a href="/dashboard" class="font-bold text-lg">{{ config('app.name', 'Nomina') }}</a>

                            @auth
                                <a href="/dashboard" class="text-gray-700 hover:text-indigo-600">Panel</a>
                            @endauth

                            @can('companies.view')
                                <a href="/empresas" class="text-gray-700 hover:text-indigo-600">Empresas</a>
                            @endcan

                            @can('users.view')
                                <a href="/usuarios" class="text-gray-700 hover:text-indigo-600">Usuarios</a>
                            @endcan

                            @can('employees.view')
                                <a href="/empleados" class="text-gray-700 hover:text-indigo-600">Empleados</a>
                            @endcan

                            @can('work_schedules.view')
                                <a href="/jornadas" class="text-gray-700 hover:text-indigo-600">Jornadas</a>
                            @endcan

                            @can('holidays.view')
                                <a href="/feriados" class="text-gray-700 hover:text-indigo-600">Feriados</a>
                            @endcan

                            @can('files.view')
                                <a href="/archivos" class="text-gray-700 hover:text-indigo-600">Archivos</a>
                            @endcan

                            @can('pay_periods.view')
                                <a href="/nomina" class="text-gray-700 hover:text-indigo-600">Nómina</a>
                            @endcan

                            @can('audit.view')
                                <a href="/auditoria" class="text-gray-700 hover:text-indigo-600">Auditoría</a>
                            @endcan

                            @can('backups.run')
                                <a href="/respaldos" class="text-gray-700 hover:text-indigo-600">Respaldos</a>
                            @endcan
                        </div>

                        <div class="flex items-center space-x-4">
                            @if (auth()->user()->hasRole('super_admin'))
                                <div class="relative" x-data="{ open: false }">
                                    <button @click="open = !open" class="text-sm text-gray-700 hover:text-indigo-600">
                                        Empresa activa: {{ current_company()?->name ?? 'Todas' }}
                                    </button>
                                    <div x-show="open" @click.away="open = false" class="absolute right-0 mt-2 w-48 bg-white rounded shadow z-10">
                                        <form method="POST" action="{{ route('current-company.update') }}">
                                            @csrf
                                            <input type="hidden" name="company" value="">
                                            <button type="submit" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">Todas</button>
                                        </form>
                                        @foreach (App\Models\Company::where('is_active', true)->orderBy('name')->get() as $company)
                                            <form method="POST" action="{{ route('current-company.update') }}">
                                                @csrf
                                                <input type="hidden" name="company" value="{{ $company->slug }}">
                                                <button type="submit" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">{{ $company->name }}</button>
                                            </form>
                                        @endforeach
                                    </div>
                                </div>
                            @else
                                <span class="text-sm text-gray-600">{{ auth()->user()->company?->name }}</span>
                            @endif

                            <span class="text-sm text-gray-600">{{ auth()->user()->email }}</span>

                            <a href="/perfil/cambiar-contrasena" class="text-sm text-gray-700 hover:text-indigo-600">Perfil</a>

                            <livewire:auth.logout />
                        </div>
                    </div>
                </div>
            </nav>
        @endauth

        <main>
            {{ $slot }}
        </main>
    </div>

    @livewireScripts
</body>
</html>
