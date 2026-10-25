{{-- A styled data table. Compose with <x-table.columns>, <x-table.column>,
     <x-table.row> and <x-table.cell>. Scrolls horizontally on small screens. --}}
<div {{ $attributes->twMerge('w-full overflow-x-auto rounded-large border border-foreground/10') }}>
    <table class="w-full caption-bottom text-sm">
        {{ $slot }}
    </table>
</div>
