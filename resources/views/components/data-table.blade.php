@props([
    'head' => null,
    'hideOnMobile' => false,
])

<div {{ $attributes->class([
    'overflow-x-auto w-full flex-1 table-scroll-shadow custom-scrollbar',
    'hidden lg:block' => $hideOnMobile,
]) }}>
    <table class="data-table relative w-full text-left border-collapse">
        @if(isset($head))
            <thead class="sticky top-0 z-10 shadow-2xs border-b border-[#E9E9E7]">
                <tr class="bg-[#F7F7F5]">
                    {{ $head }}
                </tr>
            </thead>
        @endif
        <tbody class="divide-y divide-apeiron-border bg-white">
            {{ $slot }}
        </tbody>
    </table>
</div>
