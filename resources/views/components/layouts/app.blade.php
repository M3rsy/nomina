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

    <div
        class="min-h-screen"
        @auth
            x-data="{ mobileOpen: false }"
            @resize.window="if (window.innerWidth >= 1280) { mobileOpen = false }"
            @open-company-selector.window="if (window.innerWidth < 1280) { mobileOpen = true; $nextTick(() => $refs.mobileCompanyHeading.focus()) }"
            @keydown.escape.window="if (mobileOpen) { mobileOpen = false; $nextTick(() => $refs.mobileTrigger.focus()) }"
        @endauth
    >
        @auth
            <aside class="fixed inset-y-0 left-0 z-40 hidden w-64 flex-col border-r border-slate-800 bg-[#0a0f1d] text-slate-200 xl:flex" aria-label="Navegación principal">
                <div class="flex h-16 shrink-0 items-center border-b border-slate-800 px-4">
                    <a href="{{ route('dashboard') }}" class="flex min-w-0 flex-1 items-center gap-3 rounded-lg focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-400 focus-visible:ring-offset-2 focus-visible:ring-offset-[#0a0f1d]" aria-label="Ir al panel">
                        <span class="grid size-9 shrink-0 place-items-center rounded-lg bg-indigo-600 text-white shadow-lg shadow-indigo-950/30">
                            <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M7 3.75h10A2.25 2.25 0 0 1 19.25 6v12A2.25 2.25 0 0 1 17 20.25H7A2.25 2.25 0 0 1 4.75 18V6A2.25 2.25 0 0 1 7 3.75Z" />
                                <path stroke-linecap="round" d="M8 8.25h8M8 12h3m2 0h3m-8 3.75h3m2 0h3" />
                            </svg>
                        </span>
                        <span class="truncate text-base font-bold tracking-tight text-white">{{ config('app.name', 'Nomina') }}</span>
                        <span class="ml-auto rounded-md border border-slate-700 bg-slate-900 px-1.5 py-0.5 text-[10px] font-semibold text-slate-400">v2.4</span>
                    </a>
                </div>

                <div class="shrink-0 border-b border-slate-800 p-3">
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
                                id="sidebar-company-disclosure-trigger"
                                x-ref="companyTrigger"
                                @click="open = !open"
                                aria-expanded="false"
                                :aria-expanded="open.toString()"
                                aria-controls="sidebar-company-disclosure-panel"
                                aria-label="Cambiar empresa activa. Actual: {{ $companyContextLabel }}"
                                class="flex w-full items-center gap-3 rounded-xl border border-slate-700 bg-slate-900/80 px-3 py-2.5 text-left transition hover:border-slate-600 hover:bg-slate-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-400"
                            >
                                <span class="grid size-8 shrink-0 place-items-center rounded-lg bg-slate-800 text-slate-300">
                                    <svg class="size-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M4.5 2.75A1.75 1.75 0 0 0 2.75 4.5v12.75h14.5V7.5a1.75 1.75 0 0 0-1.75-1.75h-3.25V4.5a1.75 1.75 0 0 0-1.75-1.75h-6Zm1.25 4h1.5v1.5h-1.5v-1.5Zm0 3h1.5v1.5h-1.5v-1.5Zm4-3h1.5v1.5h-1.5v-1.5Zm0 3h1.5v1.5h-1.5v-1.5Z" /></svg>
                                </span>
                                <span class="min-w-0 flex-1">
                                    <span class="block text-[10px] font-semibold uppercase tracking-wider text-slate-500">Empresa activa</span>
                                    <span class="block truncate text-sm font-semibold text-white">{{ $companyContextLabel }}</span>
                                    <span class="block truncate text-[11px] text-slate-400">{{ $companyLegalIdLabel }}</span>
                                </span>
                                <svg class="size-4 shrink-0 text-slate-500 transition" :class="open && 'rotate-180'" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M5.22 7.22a.75.75 0 0 1 1.06 0L10 10.94l3.72-3.72a.75.75 0 1 1 1.06 1.06l-4.25 4.25a.75.75 0 0 1-1.06 0L5.22 8.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd" /></svg>
                            </button>

                            <div id="sidebar-company-disclosure-panel" x-show="open" x-transition.origin.top style="display: none;" class="absolute left-0 right-0 z-50 mt-2 max-h-72 overflow-y-auto rounded-xl border border-slate-700 bg-slate-900 p-1.5 shadow-2xl">
                                <p class="px-3 py-2 text-xs font-semibold uppercase tracking-wider text-slate-500">Cambiar empresa</p>
                                <form method="POST" action="{{ route('current-company.update') }}">
                                    @csrf
                                    <input type="hidden" name="company" value="">
                                    <button type="submit"{!! $currentCompany === null ? ' aria-current="true"' : '' !!} class="block w-full rounded-lg px-3 py-2 text-left text-sm transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-indigo-400 {{ $currentCompany === null ? 'bg-indigo-600 font-medium text-white' : 'text-slate-300 hover:bg-slate-800 hover:text-white' }}">{{ $allCompaniesLabel }}</button>
                                </form>
                                @foreach ($availableCompanies as $company)
                                    <form method="POST" action="{{ route('current-company.update') }}">
                                        @csrf
                                        <input type="hidden" name="company" value="{{ $company->slug }}">
                                        <button type="submit"{!! $currentCompany?->is($company) ? ' aria-current="true"' : '' !!} class="block w-full rounded-lg px-3 py-2 text-left transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-indigo-400 {{ $currentCompany?->is($company) ? 'bg-indigo-600 text-white' : 'text-slate-300 hover:bg-slate-800 hover:text-white' }}">
                                            <span class="block truncate text-sm font-medium">{{ $company->name }}</span>
                                            <span class="block truncate text-[11px] opacity-70">{{ $company->legal_id ? 'RTN: '.$company->legal_id : 'RTN no registrado' }}</span>
                                        </button>
                                    </form>
                                @endforeach
                            </div>
                        </div>
                    @else
                        <div class="flex items-center gap-3 rounded-xl border border-slate-700 bg-slate-900/80 px-3 py-2.5">
                            <span class="grid size-8 shrink-0 place-items-center rounded-lg bg-slate-800 text-slate-300">
                                <svg class="size-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M4.5 2.75A1.75 1.75 0 0 0 2.75 4.5v12.75h14.5V7.5a1.75 1.75 0 0 0-1.75-1.75h-3.25V4.5a1.75 1.75 0 0 0-1.75-1.75h-6Z" /></svg>
                            </span>
                            <span class="min-w-0">
                                <span class="block text-[10px] font-semibold uppercase tracking-wider text-slate-500">Empresa activa</span>
                                <span class="block truncate text-sm font-semibold text-white">{{ $companyContextLabel }}</span>
                                <span class="block truncate text-[11px] text-slate-400">{{ $companyLegalIdLabel }}</span>
                            </span>
                        </div>
                    @endif
                </div>

                <nav class="flex-1 overflow-y-auto px-3 py-4" aria-label="Secciones">
                    @foreach ($navigationGroups as $group)
                        <section class="mb-5" aria-labelledby="desktop-navigation-group-{{ $loop->index }}">
                            <h2 id="desktop-navigation-group-{{ $loop->index }}" class="mb-1.5 px-3 text-[10px] font-semibold uppercase tracking-[0.18em] text-slate-500">{{ $group['label'] }}</h2>
                            <div class="space-y-0.5">
                                @foreach ($group['items'] as $item)
                                    <a href="{{ route($item['route']) }}"{!! $item['active'] ? ' aria-current="page"' : '' !!} class="group flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-400 {{ $item['active'] ? 'bg-indigo-600 text-white shadow-sm shadow-indigo-950/30' : 'text-slate-400 hover:bg-slate-800/80 hover:text-slate-100' }}">
                                        <svg class="size-[18px] shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                            @switch($item['icon'])
                                                @case('panel') <path stroke-linecap="round" stroke-linejoin="round" d="M4 4h6v6H4V4Zm10 0h6v6h-6V4ZM4 14h6v6H4v-6Zm10 0h6v6h-6v-6Z" /> @break
                                                @case('employees') <path stroke-linecap="round" stroke-linejoin="round" d="M16 20v-2a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v2m6.5-10a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm7-1 2 2 3.5-4" /> @break
                                                @case('payroll') <path stroke-linecap="round" stroke-linejoin="round" d="M6 3h12a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2Zm3 5h6m-6 4h2m2 0h2m-6 4h2m2 0h2" /> @break
                                                @case('files') <path stroke-linecap="round" stroke-linejoin="round" d="M4 4h6l2 3h8v13H4V4Z" /> @break
                                                @case('schedule') <path stroke-linecap="round" stroke-linejoin="round" d="M12 7v5l3 2m6-2a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /> @break
                                                @case('vacations') <path stroke-linecap="round" stroke-linejoin="round" d="M3 18c3-4 6-4 9 0s6 4 9 0M12 14V4m0 0C9 4 7 6 7 8m5-4c3 0 5 2 5 4" /> @break
                                                @case('holidays') <path stroke-linecap="round" stroke-linejoin="round" d="M6 3v3m12-3v3M4 9h16M5 5h14a1 1 0 0 1 1 1v14H4V6a1 1 0 0 1 1-1Z" /> @break
                                                @case('companies') <path stroke-linecap="round" stroke-linejoin="round" d="M4 21V5h10v16M8 9h2m-2 4h2m-2 4h2m6-8h4v12h-8m4-8h1m-1 4h1" /> @break
                                                @case('users') <path stroke-linecap="round" stroke-linejoin="round" d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2m7-10a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm8 0v6m3-3h-6" /> @break
                                                @case('audit') <path stroke-linecap="round" stroke-linejoin="round" d="M9 11 11 13 15 9m4 3c0 5-3 8-7 9-4-1-7-4-7-9V5l7-3 7 3v7Z" /> @break
                                                @case('backups') <path stroke-linecap="round" stroke-linejoin="round" d="M4 7c0-2 3.6-4 8-4s8 2 8 4-3.6 4-8 4-8-2-8-4Zm0 0v5c0 2 3.6 4 8 4s8-2 8-4V7M4 12v5c0 2 3.6 4 8 4s8-2 8-4v-5" /> @break
                                            @endswitch
                                        </svg>
                                        <span>{{ $item['label'] }}</span>
                                    </a>
                                @endforeach
                            </div>
                        </section>
                    @endforeach
                </nav>

                <div class="shrink-0 border-t border-slate-800 p-3">
                    <div class="mb-2 flex items-center gap-2 px-2 text-xs text-slate-400">
                        <span class="size-2 rounded-full bg-emerald-400" aria-hidden="true"></span>
                        <span>Sesión autenticada</span>
                    </div>
                    <div class="relative" x-data="{ open: false }" @resize.window="if (window.innerWidth < 1280) { open = false }" @click.outside="open = false" @focusout="if (open && !$el.contains($event.relatedTarget)) { open = false }" @keydown.escape="if (open) { $event.stopPropagation(); open = false; $nextTick(() => $refs.accountTrigger.focus()) }">
                        <div id="sidebar-account-disclosure-panel" x-show="open" style="display: none;" class="absolute bottom-full left-0 right-0 mb-2 overflow-hidden rounded-xl border border-slate-700 bg-slate-900 p-1.5 shadow-2xl">
                            <div class="border-b border-slate-800 px-3 py-2.5">
                                <p class="truncate text-sm font-medium text-white">{{ $user->email }}</p>
                                <p class="mt-0.5 truncate text-xs text-slate-400">{{ $accountContextLabel }}</p>
                            </div>
                            <a href="{{ route('profile.change-password') }}" class="mt-1 block rounded-lg px-3 py-2 text-sm text-slate-300 transition hover:bg-slate-800 hover:text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-indigo-400">{{ $changePasswordLabel }}</a>
                            <div class="[&_button]:text-slate-200 [&_button:hover]:bg-slate-800 [&_button:hover]:text-white [&_button:focus-visible]:ring-indigo-400">
                                <livewire:auth.logout />
                            </div>
                        </div>
                        <button type="button" id="sidebar-account-disclosure-trigger" x-ref="accountTrigger" @click="open = !open" aria-expanded="false" :aria-expanded="open.toString()" aria-controls="sidebar-account-disclosure-panel" aria-label="Abrir menú de cuenta de {{ $user->email }}" class="flex w-full items-center gap-3 rounded-xl bg-slate-900 px-3 py-2.5 text-left transition hover:bg-slate-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-400">
                            <span class="grid size-9 shrink-0 place-items-center rounded-full bg-indigo-600 text-sm font-semibold text-white">{{ mb_strtoupper(mb_substr($user->name ?: $user->email, 0, 1)) }}</span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-sm font-medium text-white">{{ $user->email }}</span>
                                <span class="block truncate text-[11px] text-slate-400">{{ $roleLabel }} · {{ $accountContextLabel }}</span>
                            </span>
                            <svg class="size-4 shrink-0 text-slate-500 transition" :class="open && 'rotate-180'" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M5.22 12.78a.75.75 0 0 0 1.06 0L10 9.06l3.72 3.72a.75.75 0 1 0 1.06-1.06l-4.25-4.25a.75.75 0 0 0-1.06 0l-4.25 4.25a.75.75 0 0 0 0 1.06Z" clip-rule="evenodd" /></svg>
                        </button>
                    </div>
                </div>
            </aside>

            <div class="xl:pl-64">
                <header class="sticky top-0 z-30 border-b border-slate-200 bg-white/95 shadow-sm backdrop-blur">
                    <div class="flex h-16 items-center justify-between gap-4 px-4 sm:px-6 lg:px-8">
                        <a href="{{ route('dashboard') }}" class="flex min-w-0 items-center gap-2.5 rounded-xl focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2 xl:hidden" aria-label="Ir al panel">
                            <span class="grid size-9 shrink-0 place-items-center rounded-xl bg-indigo-600 text-white shadow-sm shadow-indigo-200">
                                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M7 3.75h10A2.25 2.25 0 0 1 19.25 6v12A2.25 2.25 0 0 1 17 20.25H7A2.25 2.25 0 0 1 4.75 18V6A2.25 2.25 0 0 1 7 3.75Z" />
                                    <path stroke-linecap="round" d="M8 8.25h8M8 12h3m2 0h3m-8 3.75h3m2 0h3" />
                                </svg>
                            </span>
                            <span class="truncate text-lg font-bold tracking-tight text-slate-900">{{ config('app.name', 'Nomina') }}</span>
                        </a>

                        <div class="hidden min-w-0 xl:block">
                            <p class="text-xs font-semibold uppercase tracking-wider text-slate-400">Contexto actual</p>
                            <p class="truncate text-sm font-medium text-slate-700">{{ $companyContextLabel }}</p>
                        </div>

                        <div class="hidden min-w-0 items-center gap-2 xl:flex">
                            @if ($isSuperAdmin)
                                <div
                                    class="relative"
                                    x-data="{ open: false }"
                                    @resize.window="if (window.innerWidth < 1280) { open = false }"
                                    @open-company-selector.window="if (window.innerWidth >= 1280) { open = true; $nextTick(() => $refs.companyTrigger.focus()) }"
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
                                        class="flex max-w-56 items-center gap-2 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-left transition hover:border-indigo-200 hover:bg-indigo-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2"
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

                                    <div id="company-disclosure-panel" x-show="open" x-transition.origin.top.right style="display: none;" class="absolute right-0 mt-2 max-h-80 w-64 overflow-y-auto rounded-xl border border-slate-200 bg-white p-1.5 shadow-xl shadow-slate-200/60">
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
                                <div class="flex max-w-48 items-center gap-2 rounded-xl bg-slate-50 px-3 py-2 text-sm text-slate-600" title="{{ $companyContextLabel }}">
                                    <svg class="size-4 shrink-0 text-slate-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M4.5 2.75A1.75 1.75 0 0 0 2.75 4.5v12.75h14.5V7.5a1.75 1.75 0 0 0-1.75-1.75h-3.25V4.5a1.75 1.75 0 0 0-1.75-1.75h-6Z" /></svg>
                                    <span class="truncate">{{ $companyContextLabel }}</span>
                                </div>
                            @endif

                            <div class="relative" x-data="{ open: false }" @resize.window="if (window.innerWidth < 1280) { open = false }" @click.outside="open = false" @focusout="if (open && !$el.contains($event.relatedTarget)) { open = false }" @keydown.escape="if (open) { $event.stopPropagation(); open = false; $nextTick(() => $refs.accountTrigger.focus()) }">
                                <button type="button" id="account-disclosure-trigger" x-ref="accountTrigger" @click="open = !open" aria-expanded="false" :aria-expanded="open.toString()" aria-controls="account-disclosure-panel" aria-label="Abrir menú de cuenta de {{ $user->email }}" class="flex items-center gap-2 rounded-xl p-1.5 pr-2 transition hover:bg-slate-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2">
                                    <span class="grid size-9 shrink-0 place-items-center rounded-full bg-slate-900 text-sm font-semibold text-white">{{ mb_strtoupper(mb_substr($user->email, 0, 1)) }}</span>
                                    <span class="hidden max-w-40 truncate text-sm font-medium text-slate-700 2xl:block">{{ $user->email }}</span>
                                    <svg class="size-4 shrink-0 text-slate-400 transition" :class="open && 'rotate-180'" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M5.22 7.22a.75.75 0 0 1 1.06 0L10 10.94l3.72-3.72a.75.75 0 1 1 1.06 1.06l-4.25 4.25a.75.75 0 0 1-1.06 0L5.22 8.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd" /></svg>
                                </button>
                                <div id="account-disclosure-panel" x-show="open" style="display: none;" class="absolute right-0 mt-2 w-64 overflow-hidden rounded-xl border border-slate-200 bg-white p-1.5 shadow-xl shadow-slate-200/60">
                                    <div class="border-b border-slate-100 px-3 py-2.5">
                                        <p class="truncate text-sm font-medium text-slate-900">{{ $user->email }}</p>
                                        <p class="mt-0.5 truncate text-xs text-slate-500">{{ $accountContextLabel }}</p>
                                    </div>
                                    <a href="{{ route('profile.change-password') }}" class="mt-1 block rounded-lg px-3 py-2 text-sm text-slate-700 transition hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-indigo-500">{{ $changePasswordLabel }}</a>
                                    <livewire:auth.logout />
                                </div>
                            </div>
                        </div>

                        <button type="button" x-ref="mobileTrigger" @click="mobileOpen = !mobileOpen" :aria-expanded="mobileOpen.toString()" aria-controls="mobile-navigation-panel" :aria-label="mobileOpen ? 'Cerrar menú principal' : 'Abrir menú principal'" class="grid size-10 shrink-0 place-items-center rounded-xl border border-slate-200 text-slate-600 transition hover:bg-slate-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2 xl:hidden">
                            <svg x-show="!mobileOpen" class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" d="M4 7h16M4 12h16M4 17h16" /></svg>
                            <svg x-show="mobileOpen" style="display: none;" class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" d="m6 6 12 12M18 6 6 18" /></svg>
                        </button>
                    </div>

                    <div id="mobile-navigation-panel" x-show="mobileOpen" x-transition style="display: none;" class="max-h-[calc(100dvh-4rem)] overflow-y-auto overscroll-contain border-t border-slate-200 bg-white xl:hidden">
                        <nav class="space-y-4 px-4 py-4 sm:px-6" aria-label="Navegación móvil">
                            @foreach ($navigationGroups as $group)
                                <section @if (!$loop->first) class="border-t border-slate-100 pt-4" @endif aria-labelledby="mobile-navigation-group-{{ $loop->index }}">
                                    <h2 id="mobile-navigation-group-{{ $loop->index }}" class="mb-2 text-xs font-semibold uppercase tracking-wider text-slate-400">{{ $group['label'] }}</h2>
                                    <div class="grid grid-cols-2 gap-1 sm:grid-cols-3">
                                        @foreach ($group['items'] as $item)
                                            <a href="{{ route($item['route']) }}"{!! $item['active'] ? ' aria-current="page"' : '' !!} class="rounded-lg px-3 py-2.5 text-sm font-medium transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-indigo-500 {{ $item['active'] ? 'bg-indigo-50 text-indigo-700' : 'text-slate-700 hover:bg-slate-50' }}">{{ $item['label'] }}</a>
                                        @endforeach
                                    </div>
                                </section>
                            @endforeach

                            @if ($isSuperAdmin)
                                <section class="border-t border-slate-100 pt-4" aria-labelledby="mobile-company-heading">
                                    <h2 id="mobile-company-heading" x-ref="mobileCompanyHeading" tabindex="-1" class="mb-2 rounded text-xs font-semibold uppercase tracking-wider text-slate-400 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500">Empresa activa</h2>
                                    <div class="flex gap-2 overflow-x-auto pb-1" aria-label="Seleccionar empresa">
                                        <form method="POST" action="{{ route('current-company.update') }}" class="shrink-0">@csrf<input type="hidden" name="company" value=""><button type="submit"{!! $currentCompany === null ? ' aria-current="true"' : '' !!} class="rounded-lg px-3 py-2 text-sm transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2 {{ $currentCompany === null ? 'bg-indigo-600 font-medium text-white' : 'bg-slate-100 text-slate-700 hover:bg-slate-200' }}">{{ $allCompaniesLabel }}</button></form>
                                        @foreach ($availableCompanies as $company)
                                            <form method="POST" action="{{ route('current-company.update') }}" class="shrink-0">@csrf<input type="hidden" name="company" value="{{ $company->slug }}"><button type="submit"{!! $currentCompany?->is($company) ? ' aria-current="true"' : '' !!} class="rounded-lg px-3 py-2 text-sm transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2 {{ $currentCompany?->is($company) ? 'bg-indigo-600 font-medium text-white' : 'bg-slate-100 text-slate-700 hover:bg-slate-200' }}">{{ $company->name }}</button></form>
                                        @endforeach
                                    </div>
                                </section>
                            @endif

                            <section class="border-t border-slate-100 pt-4" aria-labelledby="mobile-account-heading">
                                <div class="min-w-0"><h2 id="mobile-account-heading" class="truncate text-sm font-medium text-slate-900">{{ $user->email }}</h2><p class="truncate text-xs text-slate-500">{{ $roleLabel }} · {{ $accountContextLabel }}</p></div>
                                <div class="mt-3 grid gap-1 sm:grid-cols-2"><a href="{{ route('profile.change-password') }}" class="rounded-lg px-3 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-indigo-500">{{ $changePasswordLabel }}</a><livewire:auth.logout /></div>
                            </section>
                        </nav>
                    </div>
                </header>

                <main id="main-content" tabindex="-1">
                    {{ $slot }}
                </main>
            </div>
        @else
            <main id="main-content" tabindex="-1">
                {{ $slot }}
            </main>
        @endauth
    </div>

    @livewireScripts
</body>
</html>
