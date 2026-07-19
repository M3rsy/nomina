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
    @auth
        <a href="#main-content" class="sr-only fixed left-4 top-4 z-50 rounded-lg bg-white px-4 py-2 text-sm font-semibold text-indigo-700 shadow-lg focus:not-sr-only focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2">
            Saltar al contenido principal
        </a>
    @endauth

    <div class="min-h-screen">
        @auth
            <nav
                aria-label="Navegación principal"
                class="sticky top-0 z-40 border-b border-slate-200 bg-white/95 shadow-sm backdrop-blur"
                x-data="{ mobileOpen: false }"
                @resize.window="if (window.innerWidth >= 1280) { mobileOpen = false }"
                @keydown.escape.window="if (mobileOpen) { mobileOpen = false; $nextTick(() => $refs.mobileTrigger.focus()) }"
            >
                <div class="mx-auto max-w-screen-2xl px-4 sm:px-6 lg:px-8">
                    <div class="flex h-16 items-center justify-between gap-4">
                        <div class="flex min-w-0 items-center gap-6">
                            <a href="{{ route('dashboard') }}" class="flex shrink-0 items-center gap-2.5 rounded-xl focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2" aria-label="Ir al panel">
                                <span class="grid size-9 place-items-center rounded-xl bg-indigo-600 text-white shadow-sm shadow-indigo-200">
                                    <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M7 3.75h10A2.25 2.25 0 0 1 19.25 6v12A2.25 2.25 0 0 1 17 20.25H7A2.25 2.25 0 0 1 4.75 18V6A2.25 2.25 0 0 1 7 3.75Z" />
                                        <path stroke-linecap="round" d="M8 8.25h8M8 12h3m2 0h3m-8 3.75h3m2 0h3" />
                                    </svg>
                                </span>
                                <span class="text-lg font-bold tracking-tight text-slate-900">{{ config('app.name', 'Nomina') }}</span>
                            </a>

                            <div class="hidden items-center gap-1 xl:flex">
                                @foreach ($primaryNavigation as $item)
                                    <a href="{{ route($item['route']) }}"{!! $item['active'] ? ' aria-current="page"' : '' !!} class="rounded-lg px-3 py-2 text-sm font-medium transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2 {{ $item['active'] ? 'bg-indigo-50 text-indigo-700' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900' }}">
                                        {{ $item['label'] }}
                                    </a>
                                @endforeach

                                @if ($managementNavigation !== [])
                                    <div
                                        class="relative"
                                        x-data="{ open: false }"
                                        @resize.window="if (window.innerWidth < 1280) { open = false }"
                                        @click.outside="open = false"
                                        @focusout="if (open && !$el.contains($event.relatedTarget)) { open = false }"
                                        @keydown.escape="if (open) { $event.stopPropagation(); open = false; $nextTick(() => $refs.managementTrigger.focus()) }"
                                    >
                                        <button
                                            type="button"
                                            id="management-disclosure-trigger"
                                            x-ref="managementTrigger"
                                            @click="open = !open"
                                            aria-expanded="false"
                                            :aria-expanded="open.toString()"
                                            aria-controls="management-disclosure-panel"
                                            class="flex items-center gap-1 rounded-lg px-3 py-2 text-sm font-medium transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2 {{ $managementActive ? 'bg-indigo-50 text-indigo-700' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900' }}"
                                        >
                                            Gestión
                                            <svg class="size-4 transition" :class="open && 'rotate-180'" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                                <path fill-rule="evenodd" d="M5.22 7.22a.75.75 0 0 1 1.06 0L10 10.94l3.72-3.72a.75.75 0 1 1 1.06 1.06l-4.25 4.25a.75.75 0 0 1-1.06 0L5.22 8.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd" />
                                            </svg>
                                        </button>

                                        <div
                                            id="management-disclosure-panel"
                                            x-show="open"
                                            x-transition.origin.top.left
                                            style="display: none;"
                                            class="absolute left-0 mt-2 w-56 overflow-hidden rounded-xl border border-slate-200 bg-white p-1.5 shadow-xl shadow-slate-200/60"
                                        >
                                            @foreach ($managementNavigation as $item)
                                                <a href="{{ route($item['route']) }}"{!! $item['active'] ? ' aria-current="page"' : '' !!} class="block rounded-lg px-3 py-2 text-sm transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-indigo-500 {{ $item['active'] ? 'bg-indigo-50 font-medium text-indigo-700' : 'text-slate-700 hover:bg-slate-50' }}">
                                                    {{ $item['label'] }}
                                                </a>
                                            @endforeach
                                        </div>
                                    </div>
                                @endif
                            </div>
                        </div>

                        <div class="hidden min-w-0 items-center gap-2 xl:flex">
                            @if ($isSuperAdmin)
                                <div
                                    class="relative"
                                    x-data="{ open: false }"
                                    @resize.window="if (window.innerWidth < 1280) { open = false }"
                                    @click.outside="open = false"
                                    @focusout="if (open && !$el.contains($event.relatedTarget)) { open = false }"
                                    @keydown.escape="if (open) { $event.stopPropagation(); open = false; $nextTick(() => $refs.companyTrigger.focus()) }"
                                >
                                    <button
                                        type="button"
                                        id="company-disclosure-trigger"
                                        x-ref="companyTrigger"
                                        @click="open = !open"
                                        aria-expanded="false"
                                        :aria-expanded="open.toString()"
                                        aria-controls="company-disclosure-panel"
                                        aria-label="Cambiar empresa activa. Actual: {{ $companyContextLabel }}"
                                        class="flex max-w-52 items-center gap-2 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-left transition hover:border-indigo-200 hover:bg-indigo-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2"
                                    >
                                        <svg class="size-4 shrink-0 text-slate-500" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                            <path d="M4.5 2.75A1.75 1.75 0 0 0 2.75 4.5v12.75h14.5V7.5a1.75 1.75 0 0 0-1.75-1.75h-3.25V4.5a1.75 1.75 0 0 0-1.75-1.75h-6Zm1.25 4h1.5v1.5h-1.5v-1.5Zm0 3h1.5v1.5h-1.5v-1.5Zm0 3h1.5v1.5h-1.5v-1.5Zm4-6h1.5v1.5h-1.5v-1.5Zm0 3h1.5v1.5h-1.5v-1.5Zm0 3h1.5v1.5h-1.5v-1.5Z" />
                                        </svg>
                                        <span class="min-w-0">
                                            <span class="block text-[10px] font-semibold uppercase tracking-wider text-slate-400">Empresa activa</span>
                                            <span class="block truncate text-sm font-medium text-slate-700">{{ $companyContextLabel }}</span>
                                        </span>
                                        <svg class="size-4 shrink-0 text-slate-400 transition" :class="open && 'rotate-180'" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                            <path fill-rule="evenodd" d="M5.22 7.22a.75.75 0 0 1 1.06 0L10 10.94l3.72-3.72a.75.75 0 1 1 1.06 1.06l-4.25 4.25a.75.75 0 0 1-1.06 0L5.22 8.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd" />
                                        </svg>
                                    </button>

                                    <div
                                        id="company-disclosure-panel"
                                        x-show="open"
                                        x-transition.origin.top.right
                                        style="display: none;"
                                        class="absolute right-0 mt-2 max-h-80 w-64 overflow-y-auto rounded-xl border border-slate-200 bg-white p-1.5 shadow-xl shadow-slate-200/60"
                                    >
                                        <p class="px-3 py-2 text-xs font-semibold uppercase tracking-wider text-slate-400">Cambiar empresa</p>
                                        <form method="POST" action="{{ route('current-company.update') }}">
                                            @csrf
                                            <input type="hidden" name="company" value="">
                                            <button type="submit"{!! $currentCompany === null ? ' aria-current="true"' : '' !!} class="block w-full rounded-lg px-3 py-2 text-left text-sm transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-indigo-500 {{ $currentCompany === null ? 'bg-indigo-50 font-medium text-indigo-700' : 'text-slate-700 hover:bg-slate-50' }}">{{ $allCompaniesLabel }}</button>
                                        </form>
                                        @foreach ($availableCompanies as $company)
                                            <form method="POST" action="{{ route('current-company.update') }}">
                                                @csrf
                                                <input type="hidden" name="company" value="{{ $company->slug }}">
                                                <button type="submit"{!! $currentCompany?->is($company) ? ' aria-current="true"' : '' !!} class="block w-full truncate rounded-lg px-3 py-2 text-left text-sm transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-indigo-500 {{ $currentCompany?->is($company) ? 'bg-indigo-50 font-medium text-indigo-700' : 'text-slate-700 hover:bg-slate-50' }}">{{ $company->name }}</button>
                                            </form>
                                        @endforeach
                                    </div>
                                </div>
                            @elseif ($currentCompany)
                                <div class="flex max-w-44 items-center gap-2 rounded-xl bg-slate-50 px-3 py-2 text-sm text-slate-600" title="{{ $companyContextLabel }}">
                                    <svg class="size-4 shrink-0 text-slate-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                        <path d="M4.5 2.75A1.75 1.75 0 0 0 2.75 4.5v12.75h14.5V7.5a1.75 1.75 0 0 0-1.75-1.75h-3.25V4.5a1.75 1.75 0 0 0-1.75-1.75h-6Z" />
                                    </svg>
                                    <span class="truncate">{{ $companyContextLabel }}</span>
                                </div>
                            @endif

                            <div
                                class="relative"
                                x-data="{ open: false }"
                                @resize.window="if (window.innerWidth < 1280) { open = false }"
                                @click.outside="open = false"
                                @focusout="if (open && !$el.contains($event.relatedTarget)) { open = false }"
                                @keydown.escape="if (open) { $event.stopPropagation(); open = false; $nextTick(() => $refs.accountTrigger.focus()) }"
                            >
                                <button
                                    type="button"
                                    id="account-disclosure-trigger"
                                    x-ref="accountTrigger"
                                    @click="open = !open"
                                    aria-expanded="false"
                                    :aria-expanded="open.toString()"
                                    aria-controls="account-disclosure-panel"
                                    aria-label="Abrir menú de cuenta de {{ $user->email }}"
                                    class="flex items-center gap-2 rounded-xl p-1.5 pr-2 transition hover:bg-slate-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2"
                                >
                                    <span class="grid size-9 shrink-0 place-items-center rounded-full bg-slate-900 text-sm font-semibold text-white">
                                        {{ mb_strtoupper(mb_substr($user->email, 0, 1)) }}
                                    </span>
                                    <span class="hidden max-w-40 truncate text-sm font-medium text-slate-700 2xl:block">{{ $user->email }}</span>
                                    <svg class="size-4 shrink-0 text-slate-400 transition" :class="open && 'rotate-180'" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                        <path fill-rule="evenodd" d="M5.22 7.22a.75.75 0 0 1 1.06 0L10 10.94l3.72-3.72a.75.75 0 1 1 1.06 1.06l-4.25 4.25a.75.75 0 0 1-1.06 0L5.22 8.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd" />
                                    </svg>
                                </button>

                                <div
                                    id="account-disclosure-panel"
                                    x-show="open"
                                    style="display: none;"
                                    class="absolute right-0 mt-2 w-64 overflow-hidden rounded-xl border border-slate-200 bg-white p-1.5 shadow-xl shadow-slate-200/60"
                                >
                                    <div class="border-b border-slate-100 px-3 py-2.5">
                                        <p class="truncate text-sm font-medium text-slate-900">{{ $user->email }}</p>
                                        <p class="mt-0.5 truncate text-xs text-slate-500">{{ $accountContextLabel }}</p>
                                    </div>
                                    <a href="{{ route('profile.change-password') }}" class="mt-1 block rounded-lg px-3 py-2 text-sm text-slate-700 transition hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-indigo-500">{{ $changePasswordLabel }}</a>
                                    <livewire:auth.logout />
                                </div>
                            </div>
                        </div>

                        <button
                            type="button"
                            x-ref="mobileTrigger"
                            @click="mobileOpen = !mobileOpen"
                            :aria-expanded="mobileOpen.toString()"
                            aria-controls="mobile-navigation-panel"
                            aria-label="Abrir menú principal"
                            :aria-label="mobileOpen ? 'Cerrar menú principal' : 'Abrir menú principal'"
                            class="grid size-10 shrink-0 place-items-center rounded-xl border border-slate-200 text-slate-600 transition hover:bg-slate-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2 xl:hidden"
                        >
                            <svg x-show="!mobileOpen" class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path stroke-linecap="round" d="M4 7h16M4 12h16M4 17h16" />
                            </svg>
                            <svg x-show="mobileOpen" style="display: none;" class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path stroke-linecap="round" d="m6 6 12 12M18 6 6 18" />
                            </svg>
                        </button>
                    </div>
                </div>

                <div id="mobile-navigation-panel" x-show="mobileOpen" x-transition style="display: none;" class="max-h-[calc(100dvh-4rem)] overflow-y-auto overscroll-contain border-t border-slate-200 bg-white xl:hidden">
                    <div class="mx-auto max-w-screen-2xl space-y-4 px-4 py-4 sm:px-6">
                        <section aria-labelledby="mobile-primary-heading">
                            <h2 id="mobile-primary-heading" class="mb-2 text-xs font-semibold uppercase tracking-wider text-slate-400">Principal</h2>
                            <div class="grid grid-cols-2 gap-1 sm:grid-cols-4">
                                @foreach ($primaryNavigation as $item)
                                    <a href="{{ route($item['route']) }}"{!! $item['active'] ? ' aria-current="page"' : '' !!} class="rounded-lg px-3 py-2.5 text-sm font-medium transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-indigo-500 {{ $item['active'] ? 'bg-indigo-50 text-indigo-700' : 'text-slate-700 hover:bg-slate-50' }}">
                                        {{ $item['label'] }}
                                    </a>
                                @endforeach
                            </div>
                        </section>

                        @if ($managementNavigation !== [])
                            <section class="border-t border-slate-100 pt-4" aria-labelledby="mobile-management-heading">
                                <h2 id="mobile-management-heading" class="mb-2 text-xs font-semibold uppercase tracking-wider text-slate-400">Gestión</h2>
                                <div class="grid grid-cols-2 gap-1 sm:grid-cols-3">
                                    @foreach ($managementNavigation as $item)
                                        <a href="{{ route($item['route']) }}"{!! $item['active'] ? ' aria-current="page"' : '' !!} class="rounded-lg px-3 py-2.5 text-sm font-medium transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-indigo-500 {{ $item['active'] ? 'bg-indigo-50 text-indigo-700' : 'text-slate-700 hover:bg-slate-50' }}">
                                            {{ $item['label'] }}
                                        </a>
                                    @endforeach
                                </div>
                            </section>
                        @endif

                        @if ($isSuperAdmin)
                            <section class="border-t border-slate-100 pt-4" aria-labelledby="mobile-company-heading">
                                <h2 id="mobile-company-heading" class="mb-2 text-xs font-semibold uppercase tracking-wider text-slate-400">Empresa activa</h2>
                                <div class="flex gap-2 overflow-x-auto pb-1" aria-label="Seleccionar empresa">
                                    <form method="POST" action="{{ route('current-company.update') }}" class="shrink-0">
                                        @csrf
                                        <input type="hidden" name="company" value="">
                                        <button type="submit"{!! $currentCompany === null ? ' aria-current="true"' : '' !!} class="rounded-lg px-3 py-2 text-sm transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2 {{ $currentCompany === null ? 'bg-indigo-600 font-medium text-white' : 'bg-slate-100 text-slate-700 hover:bg-slate-200' }}">{{ $allCompaniesLabel }}</button>
                                    </form>
                                    @foreach ($availableCompanies as $company)
                                        <form method="POST" action="{{ route('current-company.update') }}" class="shrink-0">
                                            @csrf
                                            <input type="hidden" name="company" value="{{ $company->slug }}">
                                            <button type="submit"{!! $currentCompany?->is($company) ? ' aria-current="true"' : '' !!} class="rounded-lg px-3 py-2 text-sm transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2 {{ $currentCompany?->is($company) ? 'bg-indigo-600 font-medium text-white' : 'bg-slate-100 text-slate-700 hover:bg-slate-200' }}">{{ $company->name }}</button>
                                        </form>
                                    @endforeach
                                </div>
                            </section>
                        @endif

                        <section class="border-t border-slate-100 pt-4" aria-labelledby="mobile-account-heading">
                            <div class="min-w-0">
                                <h2 id="mobile-account-heading" class="truncate text-sm font-medium text-slate-900">{{ $user->email }}</h2>
                                <p class="truncate text-xs text-slate-500">{{ $accountContextLabel }}</p>
                            </div>
                            <div class="mt-3 grid gap-1 sm:grid-cols-2">
                                <a href="{{ route('profile.change-password') }}" class="rounded-lg px-3 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-indigo-500">{{ $changePasswordLabel }}</a>
                                <livewire:auth.logout />
                            </div>
                        </section>
                    </div>
                </div>
            </nav>
        @endauth

        <main id="main-content" tabindex="-1">
            {{ $slot }}
        </main>
    </div>

    @livewireScripts
</body>
</html>
