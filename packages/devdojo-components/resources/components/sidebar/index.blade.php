@aware([
    'side' => 'left',
    'variant' => 'sidebar',
    'collapsible' => 'offcanvas'
])

{{-- display:contents wrapper hosts the Alpine scope so both the <aside> and
     the mobile scrim below share it without affecting flex layout. --}}
<div class="contents"
    x-data="{
        sidebarOpen: true,
        get isOpen() {
            return typeof isSidebarOpen !== 'undefined' ? isSidebarOpen : this.sidebarOpen;
        },
        init() {
            const handleResize = () => {
                if (window.innerWidth <= 1024) {
                    if (typeof isSidebarOpen !== 'undefined') {
                        if (isSidebarOpen) {
                            this.isSidebarOpen = false;
                            this.$dispatch('sidebar-toggle');
                        }
                    } else {
                        this.sidebarOpen = false;
                    }
                } else {
                    if (typeof isSidebarOpen !== 'undefined') {
                        if (!isSidebarOpen) {
                            this.isSidebarOpen = true;
                            this.$dispatch('sidebar-toggle');
                        }
                    } else {
                        this.sidebarOpen = true;
                    }
                }
            };
            
            window.addEventListener('resize', handleResize);
            handleResize(); // Check initial window size
            
            this.$cleanup = () => {
                window.removeEventListener('resize', handleResize);
            };
        },
        toggle() {
            if (typeof isSidebarOpen !== 'undefined') {
                this.$dispatch('sidebar-toggle');
            } else {
                this.sidebarOpen = !this.sidebarOpen;
            }
        },
        open() {
            if (typeof isSidebarOpen !== 'undefined') {
                if (!isSidebarOpen) {
                    this.$dispatch('sidebar-toggle');
                }
            } else if (!this.sidebarOpen) {
                this.sidebarOpen = true;
            }
        },
        close() {
            if (typeof isSidebarOpen !== 'undefined') {
                if (isSidebarOpen) {
                    this.$dispatch('sidebar-toggle');
                }
            } else if (this.sidebarOpen) {
                this.sidebarOpen = false;
            }
        }
    }"
    @sidebar-toggle.window="sidebarOpen = !sidebarOpen">
<aside
    x-bind:class="{
        'w-[var(--sidebar-width)]': isOpen,
        'w-[var(--sidebar-width)] lg:w-0 lg:-ml-px': !isOpen && '{{ $collapsible }}' != 'icon',
        'w-[var(--sidebar-width-icon)]': !isOpen && '{{ $collapsible }}' == 'icon',
        'lg:translate-x-0 -translate-x-full' : !isOpen,
        'translate-x-0' : isOpen
    }"
    {{ $attributes->merge([
        'style' => '--sidebar-width: 16rem; --sidebar-width-icon: 3rem;',
        'class' => \Illuminate\Support\Arr::toCssClasses([
            'fixed left-0 z-40 lg:relative flex h-dvh flex-shrink-0 border-sidebar-border flex-col overflow-hidden duration-500 ease-in-out lg:duration-200 lg:ease-linear transition-[transform] lg:transition-[width]',
            'border-l border-r-0' => $side === 'right' && $variant === 'sidebar',
            'border-r border-l-0' => $side === 'left' && $variant === 'sidebar'
        ]),
    ]) }}
>
    <div @class([
        'flex h-full fixed top-0 flex-col text-sm w-[var(--sidebar-width)] data-state-collapsed-icon:w-full',
        'right-0' => $side === 'left' && $collapsible != 'icon',
        'left-0' => $side === 'right' || ($side === 'left' && $collapsible == 'icon'),
    ])
    :class="{
        'p-2' : '{{ $variant }}' === 'floating' && isOpen,
        'px-0 py-2' : '{{ $variant }}' === 'floating' && !isOpen
    }"
    >
        <div @class([
            'bg-sidebar-background text-sidebar-foreground flex flex-col h-full',
            'rounded-large shadow-md border border-sidebar-border' => $variant === 'floating',
        ])>
            {{ $slot }}
        </div>
    </div>
</aside>
<div class="fixed inset-0 bg-black/80 z-30 lg:hidden block" x-show="isOpen" @click="close()"></div>
</div>