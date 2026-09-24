@props([
    'id' => null,
    'type' => 'success',
])

@php
    $isError = $type === 'error';
    $role = $isError ? 'alert' : 'status';
    $live = $isError ? 'assertive' : 'polite';
    $classes = $isError
        ? 'border-red-500 bg-red-50 text-red-800'
        : 'border-emerald-500 bg-emerald-50 text-emerald-800';
@endphp

<div
    @if ($id) id="{{ $id }}" @endif
    role="{{ $role }}"
    aria-live="{{ $live }}"
    {{ $attributes->class("mb-5 border-l-4 px-4 py-3 text-sm {$classes}") }}
>
    {{ $slot }}
</div>
