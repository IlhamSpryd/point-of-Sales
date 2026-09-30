<div class="hidden lg:block overflow-auto flex-1 table-scroll-shadow">
    <table class="data-table relative w-full text-left">
        @if(isset($head))
            <thead class="sticky top-0 z-10 shadow-sm">
                <tr class="bg-table-header-bg">
                    {{ $head }}
                </tr>
            </thead>
        @endif
        <tbody class="divide-y divide-apeiron-border bg-white" {{ $attributes }}>
            {{ $slot }}
        </tbody>
    </table>
</div>
