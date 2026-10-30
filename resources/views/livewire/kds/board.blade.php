@php $pollMs = random_int(4500, 5700); @endphp
<div wire:poll.{{ $pollMs }}ms.visible="$refresh" class="space-y-6" data-gsap="page-enter">

    @error('kds')
        <x-ui.alert variant="destructive" class="mb-4">
            <div class="flex items-center justify-between w-full">
                <span>{{ $message }}</span>
                <button wire:click="dismissError" class="ml-4 rounded-lg p-1 hover:bg-rose-100">
                    <span class="material-symbols-rounded text-[16px]">close</span>
                </button>
            </div>
        </x-ui.alert>
    @enderror

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3 3xl:gap-8 4xl:grid-cols-4">

        {{-- COLUMN 1: QUEUED --}}
        <section aria-labelledby="kds-pending" class="bg-white border border-gray-200 rounded-2xl flex flex-col overflow-hidden shadow-xs">
            <header class="flex items-center justify-between border-b border-gray-100 bg-gray-50/70 px-5 py-3.5">
                <h3 id="kds-pending" class="flex items-center gap-2 font-bold text-sm text-apeiron-ink">
                    <span class="size-2.5 rounded-full bg-apeiron-ink"></span> Queued
                </h3>
                <span class="rounded-lg bg-apeiron-ink px-2.5 py-0.5 text-xs font-black text-white tabular-nums">{{ $this->pendingItems->count() }}</span>
            </header>
            <div class="custom-scrollbar max-h-[70vh] flex-1 space-y-3 overflow-y-auto p-4">
                @forelse ($this->pendingItems as $item)
                    @include('livewire.kds.partials.ticket', ['item' => $item, 'variant' => 'pending'])
                @empty
                    <div class="flex flex-col items-center py-16 text-center text-gray-400">
                        <div class="mb-3 flex h-14 w-14 items-center justify-center rounded-2xl bg-gray-50 border border-gray-100 shadow-xs">
                            <span class="material-symbols-rounded text-[28px] text-gray-400">pending_actions</span>
                        </div>
                        <p class="text-xs font-semibold">No orders queued.</p>
                    </div>
                @endforelse
            </div>
        </section>

        {{-- COLUMN 2: IN PREPARATION --}}
        <section aria-labelledby="kds-brewing" class="bg-white border border-gray-200 rounded-2xl flex flex-col overflow-hidden shadow-xs">
            <header class="flex items-center justify-between border-b border-gray-100 bg-gray-50/70 px-5 py-3.5">
                <h3 id="kds-brewing" class="flex items-center gap-2 font-bold text-sm text-amber-700">
                    <span class="size-2.5 animate-pulse rounded-full bg-amber-500"></span> Preparing
                </h3>
                <span class="rounded-lg bg-amber-600 px-2.5 py-0.5 text-xs font-black text-white tabular-nums">{{ $this->brewingItems->count() }}</span>
            </header>
            <div class="custom-scrollbar max-h-[70vh] flex-1 space-y-3 overflow-y-auto p-4">
                @forelse ($this->brewingItems as $item)
                    @include('livewire.kds.partials.ticket', ['item' => $item, 'variant' => 'brewing'])
                @empty
                    <div class="flex flex-col items-center py-16 text-center text-gray-400">
                        <div class="mb-3 flex h-14 w-14 items-center justify-center rounded-2xl bg-gray-50 border border-gray-100 shadow-xs">
                            <span class="material-symbols-rounded text-[28px] text-amber-500">coffee_maker</span>
                        </div>
                        <p class="text-xs font-semibold">No items currently in preparation.</p>
                    </div>
                @endforelse
            </div>
        </section>

        {{-- COLUMN 3: READY TO SERVE --}}
        <section aria-labelledby="kds-ready" class="bg-white border border-gray-200 rounded-2xl flex flex-col overflow-hidden shadow-xs">
            <header class="flex items-center justify-between border-b border-gray-100 bg-gray-50/70 px-5 py-3.5">
                <h3 id="kds-ready" class="flex items-center gap-2 font-bold text-sm text-emerald-700">
                    <span class="material-symbols-rounded text-[18px]">check_circle</span> Ready to Serve
                </h3>
                <span class="rounded-lg bg-emerald-600 px-2.5 py-0.5 text-xs font-black text-white tabular-nums">{{ $this->readyItems->count() }}</span>
            </header>
            <div class="custom-scrollbar max-h-[70vh] flex-1 space-y-3 overflow-y-auto p-4">
                @forelse ($this->readyItems as $item)
                    @include('livewire.kds.partials.ticket', ['item' => $item, 'variant' => 'ready'])
                @empty
                    <div class="flex flex-col items-center py-16 text-center text-gray-400">
                        <div class="mb-3 flex h-14 w-14 items-center justify-center rounded-2xl bg-gray-50 border border-gray-100 shadow-xs">
                            <span class="material-symbols-rounded text-[28px] text-emerald-500">check_circle</span>
                        </div>
                        <p class="text-xs font-semibold">No items ready yet today.</p>
                    </div>
                @endforelse
            </div>
        </section>
    </div>
</div>
