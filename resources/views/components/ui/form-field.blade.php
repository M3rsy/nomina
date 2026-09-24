@props([
    'id',
    'label',
    'type' => 'text',
    'error' => null,
])

<div>
    <label for="{{ $id }}" class="block text-sm font-semibold text-slate-800">{{ $label }}</label>
    <input
        id="{{ $id }}"
        type="{{ $type }}"
        @if ($error) aria-describedby="{{ $id }}-error" aria-invalid="true" @else aria-invalid="false" @endif
        {{ $attributes->class('mt-2 block min-h-12 w-full rounded-xl border border-slate-300 bg-white px-4 text-slate-950 shadow-sm outline-none transition placeholder:text-slate-400 focus:border-indigo-600 focus:ring-4 focus:ring-indigo-100 motion-reduce:transition-none') }}
    >
    @if ($error)
        <p id="{{ $id }}-error" role="alert" class="mt-2 text-sm font-medium text-red-700">{{ $error }}</p>
    @endif
</div>
