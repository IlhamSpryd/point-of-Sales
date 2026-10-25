{{-- The table header row. Wraps <x-table.column> cells. --}}
<thead {{ $attributes->twMerge('border-b border-foreground/10 bg-secondary/40') }}>
    <tr>
        {{ $slot }}
    </tr>
</thead>
