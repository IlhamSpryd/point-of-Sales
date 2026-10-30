@php
if (! isset($scrollTo)) {
    $scrollTo = '#main-content';
}

$scrollIntoViewJsSnippet = ($scrollTo !== false)
    ? <<<JS
       const mainTarget = document.querySelector('{$scrollTo}');
       if (mainTarget) {
           mainTarget.scrollTo({ top: 0, behavior: 'smooth' });
       } else {
           window.scrollTo({ top: 0, behavior: 'smooth' });
       }
    JS
    : '';
@endphp

<div>
    @if ($paginator->hasPages())
        <nav role="navigation" aria-label="Pagination Navigation" class="flex items-center justify-between select-none">
            {{-- Mobile: Simple Previous/Next --}}
            <div class="flex justify-between flex-1 sm:hidden">
                @if ($paginator->onFirstPage())
                    <span class="relative inline-flex items-center px-4 py-2 text-sm font-medium text-[#9B9A97] bg-white border border-[#E5E7EB] cursor-not-allowed rounded-lg">
                        &laquo; Sebelumnya
                    </span>
                @else
                    <button type="button" wire:click="previousPage('{{ $paginator->getPageName() }}')" wire:loading.attr="disabled" x-on:click="{{ $scrollIntoViewJsSnippet }}" class="relative inline-flex items-center px-4 py-2 text-sm font-medium text-[#202020] bg-white border border-[#E5E7EB] rounded-lg hover:bg-[#F7F7F5] transition-colors duration-200 active:scale-[0.98] disabled:opacity-50">
                        &laquo; Sebelumnya
                    </button>
                @endif

                @if ($paginator->hasMorePages())
                    <button type="button" wire:click="nextPage('{{ $paginator->getPageName() }}')" wire:loading.attr="disabled" x-on:click="{{ $scrollIntoViewJsSnippet }}" class="relative inline-flex items-center px-4 py-2 ml-3 text-sm font-medium text-[#202020] bg-white border border-[#E5E7EB] rounded-lg hover:bg-[#F7F7F5] transition-colors duration-200 active:scale-[0.98] disabled:opacity-50">
                        Berikutnya &raquo;
                    </button>
                @else
                    <span class="relative inline-flex items-center px-4 py-2 ml-3 text-sm font-medium text-[#9B9A97] bg-white border border-[#E5E7EB] cursor-not-allowed rounded-lg">
                        Berikutnya &raquo;
                    </span>
                @endif
            </div>

            {{-- Desktop: Notion-style compact pagination --}}
            <div class="hidden sm:flex sm:flex-1 sm:items-center sm:justify-between">
                <div>
                    <p class="text-sm text-[#6B6A66] font-medium">
                        Menampilkan
                        <span class="font-semibold text-[#202020]">{{ $paginator->firstItem() ?? 0 }}</span>
                        -
                        <span class="font-semibold text-[#202020]">{{ $paginator->lastItem() ?? 0 }}</span>
                        dari
                        <span class="font-semibold text-[#202020]">{{ $paginator->total() }}</span>
                        data
                    </p>
                </div>

                <div>
                    <span class="relative z-0 inline-flex items-center gap-1">
                        {{-- Previous Page --}}
                        @if ($paginator->onFirstPage())
                            <span aria-disabled="true" class="relative inline-flex items-center p-2 text-[#9B9A97] bg-white rounded-lg cursor-not-allowed">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                            </span>
                        @else
                            <button type="button" wire:click="previousPage('{{ $paginator->getPageName() }}')" wire:loading.attr="disabled" x-on:click="{{ $scrollIntoViewJsSnippet }}" aria-label="Previous Page" class="relative inline-flex items-center p-2 text-[#6B6A66] bg-white rounded-lg hover:bg-[#F1F1EF] hover:text-[#202020] transition-colors duration-200 active:scale-[0.98] disabled:opacity-50">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                            </button>
                        @endif

                        {{-- Pagination Elements --}}
                        @foreach ($elements as $element)
                            {{-- "Three Dots" Separator --}}
                            @if (is_string($element))
                                <span class="relative inline-flex items-center px-2 py-1.5 text-sm font-medium text-[#9B9A97]">{{ $element }}</span>
                            @endif

                            {{-- Array Of Links --}}
                            @if (is_array($element))
                                @foreach ($element as $page => $url)
                                    @if ($page == $paginator->currentPage())
                                        <span aria-current="page" class="relative inline-flex items-center px-3 py-1.5 text-sm font-semibold text-white bg-[#202020] rounded-lg shadow-xs">{{ $page }}</span>
                                    @else
                                        <button type="button" wire:click="gotoPage({{ $page }}, '{{ $paginator->getPageName() }}')" wire:loading.attr="disabled" x-on:click="{{ $scrollIntoViewJsSnippet }}" class="relative inline-flex items-center px-3 py-1.5 text-sm font-medium text-[#6B6A66] rounded-lg hover:bg-[#F1F1EF] hover:text-[#202020] transition-colors duration-200 active:scale-[0.98] disabled:opacity-50">{{ $page }}</button>
                                    @endif
                                @endforeach
                            @endif
                        @endforeach

                        {{-- Next Page --}}
                        @if ($paginator->hasMorePages())
                            <button type="button" wire:click="nextPage('{{ $paginator->getPageName() }}')" wire:loading.attr="disabled" x-on:click="{{ $scrollIntoViewJsSnippet }}" aria-label="Next Page" class="relative inline-flex items-center p-2 text-[#6B6A66] bg-white rounded-lg hover:bg-[#F1F1EF] hover:text-[#202020] transition-colors duration-200 active:scale-[0.98] disabled:opacity-50">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                            </button>
                        @else
                            <span aria-disabled="true" class="relative inline-flex items-center p-2 text-[#9B9A97] bg-white rounded-lg cursor-not-allowed">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                            </span>
                        @endif
                    </span>
                </div>
            </div>
        </nav>
    @endif
</div>
