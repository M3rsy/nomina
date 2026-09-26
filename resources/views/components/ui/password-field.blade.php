@props([
    'id',
    'label',
    'hint' => null,
    'error' => null,
    'describedBy' => null,
    'invalid' => false,
    'showLabel' => 'Mostrar contraseña',
    'hideLabel' => 'Ocultar contraseña',
])

@php
    $describedByIds = collect([
        $hint ? $id.'-hint' : null,
        $describedBy,
        $error ? $id.'-error' : null,
    ])->filter()->implode(' ');
    $isInvalid = $invalid || filled($error);
@endphp

<div x-data="{ showPassword: false }">
    <label for="{{ $id }}" class="block text-sm font-semibold text-slate-800">{{ $label }}</label>
    <div class="relative mt-2">
        <span class="pointer-events-none absolute inset-y-0 left-0 grid w-11 place-items-center text-slate-400" aria-hidden="true">
            <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                <path stroke-linecap="round" stroke-linejoin="round" d="M7.5 10V7.75a4.5 4.5 0 0 1 9 0V10m-10 0h11a1.5 1.5 0 0 1 1.5 1.5v7A1.5 1.5 0 0 1 17.5 20h-11A1.5 1.5 0 0 1 5 18.5v-7A1.5 1.5 0 0 1 6.5 10Z" />
                <path stroke-linecap="round" d="M12 14v2" />
            </svg>
        </span>
        <input
            id="{{ $id }}"
            type="password"
            x-bind:type="showPassword ? 'text' : 'password'"
            @if ($describedByIds) aria-describedby="{{ $describedByIds }}" @endif
            aria-invalid="{{ $isInvalid ? 'true' : 'false' }}"
            {{ $attributes->class('block min-h-12 w-full rounded-xl border border-slate-300 bg-white py-3 pl-11 pr-12 text-slate-950 shadow-sm outline-none transition placeholder:text-slate-400 hover:border-slate-400 focus:border-indigo-600 focus:ring-4 focus:ring-indigo-100 motion-reduce:transition-none') }}
        >
        <button
            type="button"
            x-on:click="showPassword = ! showPassword"
            x-bind:aria-label="showPassword ? '{{ $hideLabel }}' : '{{ $showLabel }}'"
            x-bind:aria-pressed="showPassword"
            aria-controls="{{ $id }}"
            class="absolute inset-y-0 right-1 my-1 grid size-10 place-items-center rounded-lg text-slate-500 transition hover:bg-slate-100 hover:text-indigo-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-600 motion-reduce:transition-none"
        >
            <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M2.75 12s3.25-5.25 9.25-5.25S21.25 12 21.25 12 18 17.25 12 17.25 2.75 12 2.75 12Z" />
                <circle cx="12" cy="12" r="2.25" />
            </svg>
            <span class="sr-only" x-text="showPassword ? '{{ $hideLabel }}' : '{{ $showLabel }}'">{{ $showLabel }}</span>
        </button>
    </div>
    @if ($hint)
        <p id="{{ $id }}-hint" class="mt-2 text-xs text-slate-500">{{ $hint }}</p>
    @endif
    @if ($error)
        <p id="{{ $id }}-error" role="alert" class="mt-2 text-sm font-medium text-red-700">{{ $error }}</p>
    @endif
</div>
