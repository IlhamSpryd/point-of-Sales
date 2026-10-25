@props([
    'items' => [], // array of ['label' => '...', 'url' => '...']
])

<nav aria-label="Breadcrumb" {{ $attributes->merge(['class' => 'flex items-center text-xs text-[#6B6A66]']) }}>
    <ol class="inline-flex items-center gap-1.5 list-none p-0 m-0">
        <li class="inline-flex items-center">
            <a href="{{ route('dashboard') }}" wire:navigate class="inline-flex items-center gap-1 text-[#6B6A66] hover:text-[#202020] transition-colors font-medium">
                <span class="material-symbols-rounded text-[16px]">home</span>
                <span class="sr-only">Beranda</span>
            </a>
        </li>

        @foreach($items as $item)
            <li class="inline-flex items-center gap-1.5">
                <span class="material-symbols-rounded text-[14px] text-[#9B9A97]" aria-hidden="true">chevron_right</span>
                @if(!empty($item['url']) && !$loop->last)
                    <a href="{{ $item['url'] }}" wire:navigate class="hover:text-[#202020] transition-colors font-medium truncate max-w-[150px] sm:max-w-[200px]">
                        {{ $item['label'] }}
                    </a>
                @else
                    <span class="font-semibold text-[#202020] truncate max-w-[150px] sm:max-w-[220px]" aria-current="page">
                        {{ $item['label'] }}
                    </span>
                @endif
            </li>
        @endforeach
    </ol>
</nav>
