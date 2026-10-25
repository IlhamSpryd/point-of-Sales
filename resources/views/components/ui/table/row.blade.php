{{-- A table body row. --}}
<tr {{ $attributes->twMerge('border-b border-foreground/10 transition-colors last:border-0 hover:bg-secondary/40') }}>
    {{ $slot }}
</tr>
