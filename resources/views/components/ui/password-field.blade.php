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
        <input
            id="{{ $id }}"
            type="password"
            x-bind:type="showPassword ? 'text' : 'password'"
            @if ($describedByIds) aria-describedby="{{ $describedByIds }}" @endif
            aria-invalid="{{ $isInvalid ? 'true' : 'false' }}"
            {{ $attributes->class('block min-h-12 w-full rounded-xl border border-slate-300 bg-white px-4 pr-24 text-slate-950 shadow-sm outline-none transition focus:border-indigo-600 focus:ring-4 focus:ring-indigo-100 motion-reduce:transition-none') }}
        >
        <button
            type="button"
            x-on:click="showPassword = ! showPassword"
            x-bind:aria-label="showPassword ? '{{ $hideLabel }}' : '{{ $showLabel }}'"
            x-bind:aria-pressed="showPassword"
            aria-controls="{{ $id }}"
            class="absolute inset-y-0 right-1 my-1 min-h-10 rounded-lg px-3 text-sm font-bold text-indigo-700 transition hover:bg-indigo-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-600 motion-reduce:transition-none"
        >
            <span x-text="showPassword ? 'Ocultar' : 'Mostrar'">Mostrar</span>
        </button>
    </div>
    @if ($hint)
        <p id="{{ $id }}-hint" class="mt-2 text-xs text-slate-500">{{ $hint }}</p>
    @endif
    @if ($error)
        <p id="{{ $id }}-error" role="alert" class="mt-2 text-sm font-medium text-red-700">{{ $error }}</p>
    @endif
</div>
