@props([
    'side' => 'left', // left | right
    'variant' => 'sidebar', // sidebar | floating | inset
    'collapsible' => 'offcanvas', // offcanvas | icon | none
    'contentHeader' => false
])

<div x-data="{
    isSidebarOpen: true,
    get isOpen() {
        return this.isSidebarOpen;
    },
    get openState() {
        return this.isOpen ? 'expanded' : 'collapsed';
    },
    toggle() {
        this.isSidebarOpen = !this.isSidebarOpen;
        this.$dispatch('sidebar-toggle');
    },
    open() {
        if (!this.isSidebarOpen) {
            this.isSidebarOpen = true;
            this.$dispatch('sidebar-toggle');
        }
    },
    close() {
        if (this.isSidebarOpen) {
            this.isSidebarOpen = false;
            this.$dispatch('sidebar-toggle');
        }
    }
}" @class([
    'w-screen h-dvh text-sidebar-foreground flex items-center justify-center',
    'bg-sidebar-background' => $variant == 'inset',
    'bg-sidebar-primary-foreground' => $variant == 'floating',
]) :data-state="openState" data-collapsible="{{ $collapsible }}"
    data-side="{{ $side }}" x-cloak>
    @if ($side == 'left')
        {{ $slot ?? '' }}
    @endif

    <div @class([
        'w-full h-dvh flex z-50 relative items-center justify-center',
        'p-2 pl-2 lg:pl-0' => $variant == 'inset' && $side == 'left',
        'p-2 pr-2 lg:pr-0' => $variant == 'inset' && $side == 'right',
    ])
        :class="{
            'lg:pl-2': !isOpen && '{{ $variant }}' == 'inset' && '{{ $side }}' == 'left',
            'lg:pr-2': !isOpen && '{{ $variant }}' == 'inset' && '{{ $side }}' == 'right'
        }">
        <main @class([
            'w-full h-full bg-white flex flex-col justify-stretch',
            'shadow rounded-large' => $variant == 'inset',
        ])>
            @if($contentHeader)
                <header @class([
                    'sticky top-0 flex shrink-0 items-center gap-2 p-4',
                    'flex-row-reverse' => $side == 'right',
                    'border-b' => $variant == 'sidebar',
                    'pb-0' => $variant == 'floating' || $variant == 'inset',
                ])>
                        <x-sidebar.trigger>
                            <x-lucide-panel-left class="size-4" />
                        </x-sidebar.trigger>
                            <div data-orientation="vertical" role="none" class="shrink-0 bg-sidebar-border w-[1px] mr-2 h-4">
                            </div>
                            <nav aria-label="breadcrumb">
                                <ol
                                    class="flex flex-wrap items-center gap-1.5 break-words text-sm text-muted-foreground sm:gap-2.5">
                                    <li class="items-center gap-1.5 hidden md:block"><a
                                            class="transition-colors hover:text-foreground" href="#">All Inboxes</a></li>
                                    <li role="presentation" aria-hidden="true"
                                        class="[&amp;>svg]:w-3.5 [&amp;>svg]:h-3.5 hidden md:block">
                                        <svg @class(['w-3.5 h-3.5', 'rotate-180' => $side == 'right']) xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"
                                            fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                            stroke-linejoin="round" class="lucide lucide-chevron-right ">
                                            <path d="m9 18 6-6-6-6"></path>
                                        </svg>
                                    </li>
                                    <li class="inline-flex items-center gap-1.5"><span role="link" aria-disabled="true"
                                            aria-current="page" class="font-normal text-foreground">Inbox</span></li>
                                </ol>
                            </nav>
                </header>
            @endif

            {{ $content ?? '' }}
        </main>
    </div>
    @if ($side == 'right')
        {{ $slot ?? '' }}
    @endif
</div>
