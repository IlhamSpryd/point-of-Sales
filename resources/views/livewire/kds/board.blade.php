<div
    wire:poll.30s.visible="$refresh"
    x-data="{
        init() {
            if (window.Echo && {{ $tenantId ?? 0 }} > 0) {
                const channelName = {{ $storeId ?? 0 }} > 0
                    ? 'kds.tenant.{{ $tenantId }}.store.{{ $storeId }}'
                    : 'kds.tenant.{{ $tenantId }}';
                window.Echo.private(channelName)
                    .listen('.kds.item.updated', () => {
                        $wire.$refresh();
                    })
                    .listen('.kds.order.created', () => {
                        $wire.$refresh();
                    });
                this.$cleanup(() => {
                    if (window.Echo) {
                        window.Echo.leave(channelName);
                    }
                });
            }
        }
    }"
    class="space-y-6"
    data-gsap="page-enter"
>

    @error('kds')
        <x-ui.alert variant="destructive" class="mb-4">
            <div class="flex items-center justify-between w-full">
                <span>{{ $message }}</span>
                <button wire:click="dismissError" type="button" aria-label="Dismiss error alert" class="ml-4 rounded-lg p-1 hover:bg-destructive-muted">
                    <span class="material-symbols-rounded text-[16px]">close</span>
                </button>
            </div>
        </x-ui.alert>
    @enderror

    {{-- Station Filter Bar (FINDING-P2-05) --}}
    <div class="flex items-center justify-between card-surface bg-card border border-border rounded-2xl px-5 py-3 shadow-xs">
        <div class="flex items-center gap-2">
            <span class="material-symbols-rounded text-muted-foreground text-lg">tune</span>
            <span class="text-xs font-bold uppercase tracking-wider text-muted-foreground">Stasiun:</span>
        </div>
        <div class="flex items-center gap-2">
            <button
                type="button"
                wire:click="setStationFilter('all')"
                class="px-3.5 py-1.5 rounded-xl text-xs font-semibold transition-all cursor-pointer {{ $stationFilter === 'all' ? 'bg-primary text-primary-foreground shadow-xs' : 'bg-muted text-muted-foreground hover:text-foreground' }}">
                Semua Stasiun
            </button>
            <button
                type="button"
                wire:click="setStationFilter('kitchen')"
                class="px-3.5 py-1.5 rounded-xl text-xs font-semibold transition-all cursor-pointer {{ $stationFilter === 'kitchen' ? 'bg-warning text-on-warning shadow-xs' : 'bg-muted text-muted-foreground hover:text-foreground' }}">
                Kitchen (Food)
            </button>
            <button
                type="button"
                wire:click="setStationFilter('bar')"
                class="px-3.5 py-1.5 rounded-xl text-xs font-semibold transition-all cursor-pointer {{ $stationFilter === 'bar' ? 'bg-primary text-primary-foreground shadow-xs' : 'bg-muted text-muted-foreground hover:text-foreground' }}">
                Bar (Drinks)
            </button>
            <button
                type="button"
                wire:click="setStationFilter('dessert')"
                class="px-3.5 py-1.5 rounded-xl text-xs font-semibold transition-all cursor-pointer {{ $stationFilter === 'dessert' ? 'bg-primary text-primary-foreground shadow-xs' : 'bg-muted text-muted-foreground hover:text-foreground' }}">
                Dessert & Pastry
            </button>
        </div>
    </div>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3 3xl:gap-8 4xl:grid-cols-4">

        {{-- COLUMN 1: QUEUED --}}
        <section aria-labelledby="kds-pending" class="bg-card border border-border rounded-2xl flex flex-col overflow-hidden shadow-xs">
            <header class="flex items-center justify-between border-b border-border bg-secondary/70 px-5 py-3.5">
                <h3 id="kds-pending" class="flex items-center gap-2 font-bold text-sm text-foreground">
                    <span class="size-2.5 rounded-full bg-primary"></span> Queued
                </h3>
                <span class="rounded-lg bg-primary px-2.5 py-0.5 text-xs font-black text-primary-foreground tabular-nums">{{ $this->pendingItems->count() }}</span>
            </header>
            <div class="custom-scrollbar max-h-[70vh] flex-1 space-y-3 overflow-y-auto p-4">
                @forelse ($this->pendingItems as $item)
                    @include('livewire.kds.partials.ticket', ['item' => $item, 'variant' => 'pending'])
                @empty
                    <div class="flex flex-col items-center py-16 text-center text-muted-foreground">
                        <div class="mb-3 flex h-14 w-14 items-center justify-center rounded-2xl bg-secondary border border-border shadow-xs">
                            <span class="material-symbols-rounded text-[28px] text-muted-foreground">pending_actions</span>
                        </div>
                        <p class="text-xs font-semibold">No orders queued.</p>
                    </div>
                @endforelse
            </div>
        </section>

        {{-- COLUMN 2: IN PREPARATION --}}
        <section aria-labelledby="kds-brewing" class="bg-card border border-border rounded-2xl flex flex-col overflow-hidden shadow-xs">
            <header class="flex items-center justify-between border-b border-border bg-secondary/70 px-5 py-3.5">
                <h3 id="kds-brewing" class="flex items-center gap-2 font-bold text-sm text-warning">
                    <span class="size-2.5 animate-pulse rounded-full bg-warning"></span> Preparing
                </h3>
                <span class="rounded-lg bg-warning px-2.5 py-0.5 text-xs font-black text-on-warning tabular-nums">{{ $this->brewingItems->count() }}</span>
            </header>
            <div class="custom-scrollbar max-h-[70vh] flex-1 space-y-3 overflow-y-auto p-4">
                @forelse ($this->brewingItems as $item)
                    @include('livewire.kds.partials.ticket', ['item' => $item, 'variant' => 'brewing'])
                @empty
                    <div class="flex flex-col items-center py-16 text-center text-muted-foreground">
                        <div class="mb-3 flex h-14 w-14 items-center justify-center rounded-2xl bg-secondary border border-border shadow-xs">
                            <span class="material-symbols-rounded text-[28px] text-warning">coffee_maker</span>
                        </div>
                        <p class="text-xs font-semibold">No items currently in preparation.</p>
                    </div>
                @endforelse
            </div>
        </section>

        {{-- COLUMN 3: READY TO SERVE --}}
        <section aria-labelledby="kds-ready" class="bg-card border border-border rounded-2xl flex flex-col overflow-hidden shadow-xs">
            <header class="flex items-center justify-between border-b border-border bg-secondary/70 px-5 py-3.5">
                <h3 id="kds-ready" class="flex items-center gap-2 font-bold text-sm text-positive">
                    <span class="size-2.5 rounded-full bg-positive"></span> Ready to Serve
                </h3>
                <span class="rounded-lg bg-positive px-2.5 py-0.5 text-xs font-black text-on-positive tabular-nums">{{ $this->readyItems->count() }}</span>
            </header>
            <div class="custom-scrollbar max-h-[70vh] flex-1 space-y-3 overflow-y-auto p-4">
                @forelse ($this->readyItems as $item)
                    @include('livewire.kds.partials.ticket', ['item' => $item, 'variant' => 'ready'])
                @empty
                    <div class="flex flex-col items-center py-16 text-center text-muted-foreground">
                        <div class="mb-3 flex h-14 w-14 items-center justify-center rounded-2xl bg-secondary border border-border shadow-xs">
                            <span class="material-symbols-rounded text-[28px] text-positive">check_circle</span>
                        </div>
                        <p class="text-xs font-semibold">No items ready yet today.</p>
                    </div>
                @endforelse
            </div>
        </section>
    </div>
</div>
