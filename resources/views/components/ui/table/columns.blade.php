{{-- The table header row. Wraps <x-table.column> cells. --}}
<thead {{ $attributes->twMerge('border-b border-border bg-surface-table-header') }}>
    <tr>
        {{ $slot }}
    </tr>
</thead>
