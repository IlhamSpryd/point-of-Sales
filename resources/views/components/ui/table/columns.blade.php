{{-- The table header row. Wraps <x-table.column> cells. --}}
<thead {{ $attributes->twMerge('border-b border-border bg-[#F9FAFB]') }}>
    <tr>
        {{ $slot }}
    </tr>
</thead>
