@props([
    'items' => [], // array of ['label' => '...', 'url' => '...']
])

<nav aria-label="Breadcrumb" {{ $attributes->merge(['class' => 'flex items-center text-xs text-muted-foreground']) }}>
    <ol class="inline-flex items-center gap-1.5 list-none p-0 m-0">
        <li class="inline-flex items-center">
            <a href="{{ route('home') }}" wire:navigate class="inline-flex items-center gap-1 text-muted-foreground hover:text-foreground transition-colors font-medium">
                <x-ui.icon name="home" size="sm" />
                <span class="sr-only">Beranda</span>
            </a>
        </li>

        @foreach($items as $item)
            <li class="inline-flex items-center gap-1.5">
                <x-ui.icon name="chevron_right" size="xs" class="text-muted-foreground" />
                @if(!empty($item['url']) && !$loop->last)
                    <a href="{{ $item['url'] }}" wire:navigate class="hover:text-foreground transition-colors font-medium truncate max-w-[150px] sm:max-w-[200px]">
                        {{ $item['label'] }}
                    </a>
                @else
                    <span class="font-semibold text-foreground truncate max-w-[150px] sm:max-w-[220px]" aria-current="page">
                        {{ $item['label'] }}
                    </span>
                @endif
            </li>
        @endforeach
    </ol>
</nav>
