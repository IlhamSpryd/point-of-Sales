@if ($paginator->hasPages())
    <nav role="navigation" aria-label="Pagination Navigation" class="flex items-center justify-between">
        {{-- Mobile: Simple Previous/Next --}}
        <div class="flex justify-between flex-1 sm:hidden">
            @if ($paginator->onFirstPage())
                <span class="relative inline-flex items-center px-4 py-2 text-sm font-medium text-[#9B9A97] bg-white border border-[#E9E9E7] cursor-not-allowed rounded-lg">
                    &laquo; Sebelumnya
                </span>
            @else
                <a href="{{ $paginator->previousPageUrl() }}" class="relative inline-flex items-center px-4 py-2 text-sm font-medium text-[#37352F] bg-white border border-[#E9E9E7] rounded-lg hover:bg-[#F7F7F5] transition-colors duration-200 active:scale-[0.98]">
                    &laquo; Sebelumnya
                </a>
            @endif

            @if ($paginator->hasMorePages())
                <a href="{{ $paginator->nextPageUrl() }}" class="relative inline-flex items-center px-4 py-2 ml-3 text-sm font-medium text-[#37352F] bg-white border border-[#E9E9E7] rounded-lg hover:bg-[#F7F7F5] transition-colors duration-200 active:scale-[0.98]">
                    Berikutnya &raquo;
                </a>
            @else
                <span class="relative inline-flex items-center px-4 py-2 ml-3 text-sm font-medium text-[#9B9A97] bg-white border border-[#E9E9E7] cursor-not-allowed rounded-lg">
                    Berikutnya &raquo;
                </span>
            @endif
        </div>

        {{-- Desktop: Notion-style compact pagination --}}
        <div class="hidden sm:flex sm:flex-1 sm:items-center sm:justify-between">
            <div>
                <p class="text-sm text-[#787774] font-medium">
                    Menampilkan
                    <span class="font-semibold text-[#37352F]">{{ $paginator->firstItem() ?? 0 }}</span>
                    -
                    <span class="font-semibold text-[#37352F]">{{ $paginator->lastItem() ?? 0 }}</span>
                    dari
                    <span class="font-semibold text-[#37352F]">{{ $paginator->total() }}</span>
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
                        <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="relative inline-flex items-center p-2 text-[#787774] bg-white rounded-lg hover:bg-[#F1F1EF] hover:text-[#37352F] transition-colors duration-200 active:scale-[0.98]">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                        </a>
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
                                    <span aria-current="page" class="relative inline-flex items-center px-3 py-1.5 text-sm font-semibold text-white bg-[#37352F] rounded-lg shadow-sm">{{ $page }}</span>
                                @else
                                    <a href="{{ $url }}" class="relative inline-flex items-center px-3 py-1.5 text-sm font-medium text-[#787774] rounded-lg hover:bg-[#F1F1EF] hover:text-[#37352F] transition-colors duration-200 active:scale-[0.98]">{{ $page }}</a>
                                @endif
                            @endforeach
                        @endif
                    @endforeach

                    {{-- Next Page --}}
                    @if ($paginator->hasMorePages())
                        <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="relative inline-flex items-center p-2 text-[#787774] bg-white rounded-lg hover:bg-[#F1F1EF] hover:text-[#37352F] transition-colors duration-200 active:scale-[0.98]">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                        </a>
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
