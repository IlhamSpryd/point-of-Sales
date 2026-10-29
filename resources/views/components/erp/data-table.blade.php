@props([
    'emptyTitle' => 'Belum ada data',
    'emptyDescription' => 'Data yang Anda cari belum tersedia atau tidak cocok dengan filter aktif.',
    'emptyIcon' => 'inbox',
    'isEmpty' => false,
    'colspan' => 6,
])

<div {{ $attributes->twMerge('card-surface flex flex-col overflow-hidden') }}>
    @isset($toolbar)
        <div class="px-5 py-3.5 border-b border-border bg-secondary/30 flex flex-wrap items-center justify-between gap-3">
            {{ $toolbar }}
        </div>
    @endisset

    <div class="w-full overflow-x-auto table-scroll-shadow">
        <table class="w-full caption-bottom text-xs sm:text-sm border-collapse">
            @isset($head)
                <thead class="border-b border-border bg-[#F7F7F5]">
                    <tr>
                        {{ $head }}
                    </tr>
                </thead>
            @endisset

            <tbody class="divide-y divide-border bg-card">
                @if ($isEmpty)
                    <tr>
                        <td colspan="{{ $colspan }}" class="p-0">
                            <x-ui.empty-state :title="$emptyTitle" :description="$emptyDescription" class="border-0 py-14">
                                <x-slot:icon>
                                    <span class="material-symbols-rounded text-2xl" aria-hidden="true">{{ $emptyIcon }}</span>
                                </x-slot:icon>
                                @isset($emptyAction)
                                    {{ $emptyAction }}
                                @endisset
                            </x-ui.empty-state>
                        </td>
                    </tr>
                @else
                    {{ $body ?? $slot }}
                @endif
            </tbody>

            @isset($foot)
                <tfoot class="border-t-2 border-border bg-[#F7F7F5] font-semibold">
                    {{ $foot }}
                </tfoot>
            @endisset
        </table>
    </div>

    @isset($pagination)
        <div class="px-5 py-3.5 border-t border-border bg-card flex items-center justify-between gap-3">
            {{ $pagination }}
        </div>
    @endisset
</div>
