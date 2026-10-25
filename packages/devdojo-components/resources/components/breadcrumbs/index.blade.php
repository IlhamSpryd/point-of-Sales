{{-- A breadcrumb trail. Compose with <x-breadcrumbs.item> children. --}}
<nav aria-label="Breadcrumb" {{ $attributes->twMerge('') }}>
    <ol class="flex flex-wrap items-center gap-1.5">
        {{ $slot }}
    </ol>
</nav>
