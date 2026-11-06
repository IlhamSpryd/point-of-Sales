{{-- A table body row. --}}
<tr {{ $attributes->twMerge('border-b border-border transition-colors last:border-0 hover:bg-accent') }}>
    {{ $slot }}
</tr>
