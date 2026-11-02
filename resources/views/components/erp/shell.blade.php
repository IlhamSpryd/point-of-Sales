@props([
    'title' => '',
    'description' => null,
    'subtitle' => null,
    'breadcrumbs' => [],
    'badge' => null,
    'badgeVariant' => 'secondary',
])

<div {{ $attributes->twMerge('flex flex-col flex-1 min-h-0 w-full') }}>
    @if($title || !empty($breadcrumbs) || isset($actions))
        <x-erp.page-header
            :title="$title"
            :description="$description ?? $subtitle"
            :breadcrumbs="$breadcrumbs"
            :badge="$badge"
            :badgeVariant="$badgeVariant"
        >
            @isset($actions)
                <x-slot:actions>
                    {{ $actions }}
                </x-slot:actions>
            @endisset
        </x-erp.page-header>
    @endif

    @isset($filters)
        <div class="mb-4">
            {{ $filters }}
        </div>
    @endisset

    <div class="flex-1 min-h-0">
        {{ $slot }}
    </div>
</div>
