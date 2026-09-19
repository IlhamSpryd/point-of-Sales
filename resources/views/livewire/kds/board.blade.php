<div wire:poll.5s="$refresh" class="space-y-6">

    @error('kds')
        <div role="alert" class="flex items-center gap-2 rounded-2xl border border-rose-200 bg-rose-50 p-4 text-sm font-medium text-rose-700">
            <span class="material-symbols-rounded text-[18px]">error</span> {{ $message }}
        </div>
    @enderror

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">

        {{-- KOLOM 1: MENUNGGU --}}
        <section aria-labelledby="kds-pending" class="card-surface flex flex-col overflow-hidden">
            <header class="flex items-center justify-between border-b border-yovel-border bg-yovel-bg px-5 py-4">
                <h3 id="kds-pending" class="flex items-center gap-2.5 font-bold">
                    <span class="h-2.5 w-2.5 rounded-full border-2 border-yovel-ink"></span> Menunggu
                </h3>
                <span class="rounded-lg border border-yovel-border bg-white px-2.5 py-1 text-xs font-bold tabular-nums">{{ $this->pendingItems->count() }}</span>
            </header>
            <div class="custom-scrollbar max-h-[70vh] flex-1 space-y-3 overflow-y-auto p-4">
                @forelse ($this->pendingItems as $item)
                    @include('livewire.kds.partials.ticket', ['item' => $item, 'variant' => 'pending'])
                @empty
                    <div class="flex flex-col items-center py-16 text-center">
                        <div class="mb-3 flex h-16 w-16 items-center justify-center rounded-2xl bg-yovel-surface">
                            <span class="material-symbols-rounded text-[32px] text-primary-300">pending_actions</span>
                        </div>
                        <p class="text-sm font-medium text-yovel-muted">Tidak ada pesanan menunggu.</p>
                    </div>
                @endforelse
            </div>
        </section>

        {{-- KOLOM 2: SEDANG DIRACIK --}}
        <section aria-labelledby="kds-brewing" class="card-surface flex flex-col overflow-hidden">
            <header class="flex items-center justify-between border-b border-yovel-border bg-yovel-bg px-5 py-4">
                <h3 id="kds-brewing" class="flex items-center gap-2.5 font-bold">
                    <span class="h-2.5 w-2.5 animate-pulse rounded-full bg-yovel-ink"></span> Sedang Diracik
                </h3>
                <span class="rounded-lg border border-yovel-border bg-white px-2.5 py-1 text-xs font-bold tabular-nums">{{ $this->brewingItems->count() }}</span>
            </header>
            <div class="custom-scrollbar max-h-[70vh] flex-1 space-y-3 overflow-y-auto p-4">
                @forelse ($this->brewingItems as $item)
                    @include('livewire.kds.partials.ticket', ['item' => $item, 'variant' => 'brewing'])
                @empty
                    <div class="flex flex-col items-center py-16 text-center">
                        <div class="mb-3 flex h-16 w-16 items-center justify-center rounded-2xl bg-yovel-surface">
                            <span class="material-symbols-rounded text-[32px] text-primary-300">coffee_maker</span>
                        </div>
                        <p class="text-sm font-medium text-yovel-muted">Tidak ada item sedang diracik.</p>
                    </div>
                @endforelse
            </div>
        </section>

        {{-- KOLOM 3: SIAP DIANTAR --}}
        <section aria-labelledby="kds-ready" class="card-surface flex flex-col overflow-hidden">
            <header class="flex items-center justify-between border-b border-yovel-border bg-yovel-bg px-5 py-4">
                <h3 id="kds-ready" class="flex items-center gap-2.5 font-bold">
                    <span class="material-symbols-rounded text-[16px]">check_circle</span> Siap Diantar
                </h3>
                <span class="rounded-lg border border-yovel-border bg-white px-2.5 py-1 text-xs font-bold tabular-nums">{{ $this->readyItems->count() }}</span>
            </header>
            <div class="custom-scrollbar max-h-[70vh] flex-1 space-y-3 overflow-y-auto p-4">
                @forelse ($this->readyItems as $item)
                    @include('livewire.kds.partials.ticket', ['item' => $item, 'variant' => 'ready'])
                @empty
                    <div class="flex flex-col items-center py-16 text-center">
                        <div class="mb-3 flex h-16 w-16 items-center justify-center rounded-2xl bg-yovel-surface">
                            <span class="material-symbols-rounded text-[32px] text-primary-300">check_circle</span>
                        </div>
                        <p class="text-sm font-medium text-yovel-muted">Belum ada item selesai hari ini.</p>
                    </div>
                @endforelse
            </div>
        </section>
    </div>
</div>
