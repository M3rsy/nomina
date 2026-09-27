@props([
    'id',
    'label',
    'type' => 'text',
    'error' => null,
    'icon' => null,
])

<div>
    <label for="{{ $id }}" class="block text-sm font-semibold text-slate-800">{{ $label }}</label>
    <div class="relative mt-2">
        @if ($icon)
            <span class="pointer-events-none absolute inset-y-0 left-0 grid w-11 place-items-center text-slate-400" aria-hidden="true">
                @if ($icon === 'mail')
                    <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75A2.25 2.25 0 0 1 6 4.5h12a2.25 2.25 0 0 1 2.25 2.25v10.5A2.25 2.25 0 0 1 18 19.5H6a2.25 2.25 0 0 1-2.25-2.25V6.75Z" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 7.5 6.12 4.08a2.5 2.5 0 0 0 2.76 0L19.5 7.5" />
                    </svg>
                @else
                    <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                        <circle cx="12" cy="8" r="3.25" />
                        <path stroke-linecap="round" d="M5.75 19.25a6.25 6.25 0 0 1 12.5 0" />
                    </svg>
                @endif
            </span>
        @endif
        <input
            id="{{ $id }}"
            type="{{ $type }}"
            @if ($error) aria-describedby="{{ $id }}-error" aria-invalid="true" @else aria-invalid="false" @endif
            {{ $attributes->class([
                'block min-h-12 w-full rounded-xl border border-slate-300 bg-white py-3 text-slate-950 shadow-sm outline-none transition placeholder:text-slate-400 hover:border-slate-400 focus:border-indigo-600 focus:ring-4 focus:ring-indigo-100 motion-reduce:transition-none',
                'pl-11 pr-4' => filled($icon),
                'px-4' => blank($icon),
            ]) }}
        >
    </div>
    @if ($error)
        <p id="{{ $id }}-error" role="alert" class="mt-2 text-sm font-medium text-red-700">{{ $error }}</p>
    @endif
</div>
