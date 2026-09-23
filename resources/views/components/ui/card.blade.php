@props(['padding' => true])

<section {{ $attributes->class('overflow-hidden rounded-3xl border border-border bg-surface shadow-sm') }}>
    @isset($header)
        <div class="border-b border-border px-5 py-4 sm:px-7">
            {{ $header }}
        </div>
    @endisset

    <div @class(['p-5 sm:p-7' => $padding])>
        {{ $slot }}
    </div>

    @isset($footer)
        <div class="border-t border-border bg-surface-muted px-5 py-4 sm:px-7">
            {{ $footer }}
        </div>
    @endisset
</section>
