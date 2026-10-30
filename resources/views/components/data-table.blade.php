@props([
    'head' => null,
    'colgroup' => null,
    'foot' => null,
    'fixed' => false,
    'hideOnMobile' => false,
])

<div {{ $attributes->class([
    'overflow-x-auto w-full flex-1 table-scroll-shadow custom-scrollbar',
    'hidden lg:block' => $hideOnMobile,
]) }}>
    <table class="data-table table-standard relative w-full min-w-full {{ $fixed ? 'table-fixed' : '' }}">
        @isset($colgroup)
            <colgroup>
                {{ $colgroup }}
            </colgroup>
        @endisset
        @if(isset($head))
            <thead class="sticky top-0 z-10 shadow-2xs border-b border-[#E5E7EB]">
                <tr class="bg-[#F9FAFB]">
                    {{ $head }}
                </tr>
            </thead>
        @endif
        <tbody class="divide-y divide-apeiron-border bg-white">
            {{ $slot }}
        </tbody>
        @isset($foot)
            <tfoot class="border-t-2 border-[#E5E7EB] bg-[#F9FAFB] font-semibold">
                {{ $foot }}
            </tfoot>
        @endisset
    </table>
</div>
