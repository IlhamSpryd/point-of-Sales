@props([
    'columns' => [],
    'rows' => null,
    'sortField' => null,
    'sortDirection' => 'asc',
    'emptyTitle' => 'No records found',
    'emptyDescription' => 'The requested records are not available or do not match active filters.',
    'emptyIcon' => 'inbox',
    'isEmpty' => null,
    'colspan' => null,
    'fixed' => false,
    'hideOnMobile' => false,
    'loadingTarget' => null,
    'skeletonRows' => 5,
    'striped' => false,
])

@php
    $columnCount = !empty($columns) ? count($columns) : ($colspan ?? 6);
    $calculatedEmpty = $isEmpty !== null 
        ? (bool) $isEmpty 
        : ($rows !== null && (is_countable($rows) ? count($rows) === 0 : false));
@endphp

<div {{ $attributes->twMerge('card-surface flex flex-col overflow-hidden w-full') }}>
    @isset($toolbar)
        <div class="px-4 py-3 border-b border-border bg-muted flex flex-wrap items-center justify-between gap-3">
            {{ $toolbar }}
        </div>
    @endisset

    <div @class([
        'w-full overflow-x-auto table-scroll-shadow custom-scrollbar',
        'hidden lg:block' => $hideOnMobile,
    ])>
        <table class="data-table table-standard relative w-full min-w-full caption-bottom text-xs sm:text-sm border-collapse {{ $fixed ? 'table-fixed' : '' }}">
            @isset($colgroup)
                <colgroup>
                    {{ $colgroup }}
                </colgroup>
            @endisset

            @if(!empty($columns))
                <thead class="sticky top-0 z-10 border-b border-border bg-surface-table-header shadow-2xs">
                    <tr>
                        @foreach($columns as $col)
                            @php
                                $align = $col['align'] ?? (!empty($col['numeric']) ? 'right' : 'left');
                                $isSortable = !empty($col['sortable']);
                                $key = $col['key'] ?? null;
                                $isCurrentSort = $key && $sortField === $key;
                            @endphp
                            <th scope="col" class="px-4 py-3 text-2xs font-semibold uppercase tracking-wider text-muted-foreground whitespace-nowrap {{ $align === 'right' ? 'text-right' : ($align === 'center' ? 'text-center' : 'text-left') }} {{ !empty($col['numeric']) ? 'tabular-nums numeric' : '' }} {{ $col['class'] ?? '' }}">
                                @if($isSortable && $key)
                                    <button type="button" wire:click="sortBy('{{ $key }}')" class="group inline-flex items-center gap-1.5 font-semibold text-muted-foreground hover:text-foreground transition-colors focus:outline-hidden focus-visible:ring-1 focus-visible:ring-ring rounded-sm {{ $align === 'right' ? 'flex-row-reverse' : '' }}">
                                        <span>{{ $col['label'] ?? '' }}</span>
                                        <span class="inline-flex transition-transform">
                                            @if($isCurrentSort)
                                                <x-ui.icon :name="$sortDirection === 'desc' ? 'arrow_downward' : 'arrow_upward'" size="xs" class="text-primary font-bold" />
                                            @else
                                                <x-ui.icon name="unfold_more" size="xs" class="opacity-70 group-hover:opacity-100 text-muted-foreground" />
                                            @endif
                                        </span>
                                    </button>
                                @else
                                    <span>{{ $col['label'] ?? '' }}</span>
                                @endif
                            </th>
                        @endforeach
                    </tr>
                </thead>
            @elseif(isset($head))
                <thead class="sticky top-0 z-10 border-b border-border bg-surface-table-header shadow-2xs">
                    <tr class="bg-surface-table-header">
                        {{ $head }}
                    </tr>
                </thead>
            @endif

            <tbody class="divide-y divide-border bg-card">
                @if($loadingTarget)
                    <tr wire:loading.table wire:target="{{ $loadingTarget }}">
                        <td colspan="{{ $columnCount }}" class="p-0">
                            <div class="divide-y divide-border/60 bg-card">
                                @for($s = 0; $s < $skeletonRows; $s++)
                                    <div class="flex items-center gap-4 px-4 py-3.5 animate-pulse">
                                        <div class="h-4 bg-muted rounded-medium w-1/4"></div>
                                        <div class="h-4 bg-muted rounded-medium w-1/3"></div>
                                        <div class="h-4 bg-muted rounded-medium w-1/6 ml-auto"></div>
                                    </div>
                                @endfor
                            </div>
                        </td>
                    </tr>
                @endif

                @if ($calculatedEmpty)
                    <tr @if($loadingTarget) wire:loading.remove wire:target="{{ $loadingTarget }}" @endif>
                        <td colspan="{{ $columnCount }}" class="p-0">
                            <x-ui.empty-state :title="$emptyTitle" :description="$emptyDescription" class="border-0 py-12">
                                <x-slot:icon>
                                    <x-ui.icon :name="$emptyIcon" size="xl" />
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
                <tfoot class="border-t-2 border-border bg-surface-table-header font-semibold">
                    {{ $foot }}
                </tfoot>
            @endisset
        </table>
    </div>

    @isset($pagination)
        <div class="px-4 py-3 border-t border-border bg-card flex flex-wrap items-center justify-between gap-3 text-xs sm:text-sm">
            {{ $pagination }}
        </div>
    @elseif($rows instanceof \Illuminate\Pagination\LengthAwarePaginator || $rows instanceof \Illuminate\Pagination\Paginator)
        @if($rows->hasPages())
            <div class="px-4 py-3 border-t border-border bg-card flex flex-wrap items-center justify-between gap-3 text-xs sm:text-sm">
                {{ $rows->links() }}
            </div>
        @endif
    @endisset
</div>
