@php
if (! isset($scrollTo)) {
    $scrollTo = 'body';
}

$scrollIntoViewJsSnippet = ($scrollTo !== false)
    ? <<<JS
       (\$el.closest('{$scrollTo}') || document.querySelector('{$scrollTo}')).scrollIntoView()
    JS
    : '';
@endphp

<div>
    @if ($paginator->hasPages())
        <nav role="navigation" aria-label="Pagination Navigation" class="flex items-center justify-between">
            {{-- Mobile: Simple Previous/Next --}}
            <div class="flex justify-between flex-1 sm:hidden">
                @if ($paginator->onFirstPage())
                    <span class="relative inline-flex items-center px-4 py-2 text-sm font-medium text-yovel-muted bg-white border border-yovel-border cursor-not-allowed rounded-lg">
                        &laquo; Sebelumnya
                    </span>
                @else
                    <button type="button" wire:click="previousPage('{{ $paginator->getPageName() }}')" x-on:click="{{ $scrollIntoViewJsSnippet }}" class="relative inline-flex items-center px-4 py-2 text-sm font-medium text-yovel-ink bg-white border border-yovel-border rounded-lg hover:bg-yovel-bg transition-colors duration-200 active:scale-[0.98]">
                        &laquo; Sebelumnya
                    </button>
                @endif

                @if ($paginator->hasMorePages())
                    <button type="button" wire:click="nextPage('{{ $paginator->getPageName() }}')" x-on:click="{{ $scrollIntoViewJsSnippet }}" class="relative inline-flex items-center px-4 py-2 ml-3 text-sm font-medium text-yovel-ink bg-white border border-yovel-border rounded-lg hover:bg-yovel-bg transition-colors duration-200 active:scale-[0.98]">
                        Berikutnya &raquo;
                    </button>
                @else
                    <span class="relative inline-flex items-center px-4 py-2 ml-3 text-sm font-medium text-yovel-muted bg-white border border-yovel-border cursor-not-allowed rounded-lg">
                        Berikutnya &raquo;
                    </span>
                @endif
            </div>

            {{-- Desktop: Notion-style compact pagination --}}
            <div class="hidden sm:flex sm:flex-1 sm:items-center sm:justify-between">
                <div>
                    <p class="text-sm text-yovel-muted font-medium">
                        Menampilkan
                        <span class="font-semibold text-yovel-ink">{{ $paginator->firstItem() ?? 0 }}</span>
                        -
                        <span class="font-semibold text-yovel-ink">{{ $paginator->lastItem() ?? 0 }}</span>
                        dari
                        <span class="font-semibold text-yovel-ink">{{ $paginator->total() }}</span>
                        data
                    </p>
                </div>

                <div>
                    <span class="relative z-0 inline-flex items-center gap-1">
                        {{-- Previous Page --}}
                        @if ($paginator->onFirstPage())
                            <span aria-disabled="true" class="relative inline-flex items-center p-2 text-yovel-muted bg-white rounded-lg cursor-not-allowed">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                            </span>
                        @else
                            <button type="button" wire:click="previousPage('{{ $paginator->getPageName() }}')" x-on:click="{{ $scrollIntoViewJsSnippet }}" class="relative inline-flex items-center p-2 text-yovel-muted bg-white rounded-lg hover:bg-yovel-surface hover:text-yovel-ink transition-colors duration-200 active:scale-[0.98]">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                            </button>
                        @endif

                        {{-- Pagination Elements --}}
                        @foreach ($elements as $element)
                            {{-- "Three Dots" Separator --}}
                            @if (is_string($element))
                                <span class="relative inline-flex items-center px-2 py-1.5 text-sm font-medium text-yovel-muted">{{ $element }}</span>
                            @endif

                            {{-- Array Of Links --}}
                            @if (is_array($element))
                                @foreach ($element as $page => $url)
                                    @if ($page == $paginator->currentPage())
                                        <span aria-current="page" class="relative inline-flex items-center px-3 py-1.5 text-sm font-semibold text-white bg-yovel-ink rounded-lg shadow-sm">{{ $page }}</span>
                                    @else
                                        <button type="button" wire:click="gotoPage({{ $page }}, '{{ $paginator->getPageName() }}')" x-on:click="{{ $scrollIntoViewJsSnippet }}" class="relative inline-flex items-center px-3 py-1.5 text-sm font-medium text-yovel-muted rounded-lg hover:bg-yovel-surface hover:text-yovel-ink transition-colors duration-200 active:scale-[0.98]">{{ $page }}</button>
                                    @endif
                                @endforeach
                            @endif
                        @endforeach

                        {{-- Next Page --}}
                        @if ($paginator->hasMorePages())
                            <button type="button" wire:click="nextPage('{{ $paginator->getPageName() }}')" x-on:click="{{ $scrollIntoViewJsSnippet }}" class="relative inline-flex items-center p-2 text-yovel-muted bg-white rounded-lg hover:bg-yovel-surface hover:text-yovel-ink transition-colors duration-200 active:scale-[0.98]">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                            </button>
                        @else
                            <span aria-disabled="true" class="relative inline-flex items-center p-2 text-yovel-muted bg-white rounded-lg cursor-not-allowed">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                            </span>
                        @endif
                    </span>
                </div>
            </div>
        </nav>
    @endif
</div>
