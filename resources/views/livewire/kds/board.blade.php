<div wire:poll.5s="$refresh" class="space-y-6">

    @error('kds')
        <div class="p-4 rounded-2xl bg-rose-50 border border-rose-200 text-rose-700 text-sm font-medium flex items-center gap-2">
            <span class="material-symbols-rounded">error</span> {{ $message }}
        </div>
    @enderror

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

        {{-- KOLOM 1: MENUNGGU --}}
        <div class="card-surface overflow-hidden flex flex-col">
            <div class="px-5 py-4 border-b border-[#E9E9E7] flex items-center justify-between bg-amber-50/80">
                <h3 class="font-bold text-[#37352F] flex items-center gap-2.5">
                    <span class="w-2.5 h-2.5 rounded-full bg-amber-500 animate-pulse"></span>
                    Menunggu
                </h3>
                <span class="text-xs font-bold text-amber-700 bg-amber-100 px-2.5 py-1 rounded-lg border border-amber-200">{{ $this->pendingItems->count() }}</span>
            </div>
            <div class="p-4 space-y-3 flex-1 overflow-y-auto max-h-[70vh] custom-scrollbar">
                @forelse($this->pendingItems as $item)
                    <div wire:key="pending-{{ $item->id }}" class="bg-white rounded-2xl border border-amber-200/80 p-5 shadow-sm hover:shadow-md transition-all duration-200 animate-slide-up-fade">
                        <div class="flex items-start justify-between gap-2 mb-3">
                            <div>
                                <p class="text-[10px] font-black text-amber-600 uppercase tracking-widest bg-amber-50 px-2 py-0.5 rounded-md inline-block border border-amber-200/50">{{ $item->order->order_code }}</p>
                                <p class="text-base font-bold text-[#37352F] mt-1.5">
                                    {{ $item->order->table ? 'Meja '.$item->order->table->table_number : 'Kasir' }}
                                </p>
                            </div>
                        </div>

                        <div class="flex items-center gap-2 mb-2">
                            <span class="bg-[#F7F7F5] text-[#37352F] text-xs font-bold px-2 py-1 rounded-lg border border-[#E9E9E7]">{{ $item->qty }}×</span>
                            <span class="text-sm font-bold text-[#37352F]">{{ $item->product->product_name }}</span>
                        </div>

                        @if(!empty($item->options))
                            <div class="text-xs text-[#787774] space-y-0.5 ml-0.5">
                                @foreach($item->options as $opt)
                                    <div class="flex items-center gap-1.5">
                                        <span class="w-1 h-1 rounded-full bg-[#C4C3C0]"></span>
                                        {{ $opt['name'] ?? '' }}
                                    </div>
                                @endforeach
                            </div>
                        @endif

                        @if($item->notes)
                            <div class="text-xs text-[#787774] bg-[#F7F7F5] px-3 py-1.5 rounded-lg mt-2 border border-[#E9E9E7] flex items-start gap-1.5">
                                <span class="material-symbols-rounded text-[14px] mt-0.5 text-[#9B9A97]">sticky_note_2</span>
                                {{ $item->notes }}
                            </div>
                        @endif

                        @php $slaLevel = $item->slaLevel(); @endphp
                        <div class="mt-3">
                            <span @class([
                                'inline-flex items-center gap-1 text-xs font-bold px-2.5 py-1 rounded-lg',
                                'bg-[#F7F7F5] text-[#787774] border border-[#E9E9E7]' => $slaLevel === 'ok',
                                'bg-amber-100 text-amber-700 border border-amber-200 animate-pulse' => $slaLevel === 'warning',
                                'bg-rose-100 text-rose-700 border border-rose-200 animate-pulse' => $slaLevel === 'critical',
                            ])>
                                <span class="material-symbols-rounded text-[14px]">schedule</span>
                                {{ $item->slaMinutesElapsed() }} menit
                            </span>
                        </div>

                        <button wire:click="claim({{ $item->id }})" wire:loading.attr="disabled" wire:key="claim-btn-{{ $item->id }}"
                                class="w-full mt-4 bg-[#37352F] text-white text-sm font-bold py-3.5 rounded-xl hover:bg-black transition-all duration-200 disabled:opacity-50 active:scale-[0.97] shadow-sm flex items-center justify-center gap-2">
                            <span class="material-symbols-rounded text-[18px]">skillet</span>
                            Mulai Racik
                        </button>
                    </div>
                @empty
                    <div class="flex flex-col items-center justify-center py-16 text-center">
                        <div class="w-16 h-16 rounded-2xl bg-amber-50 flex items-center justify-center mb-3 border border-amber-100">
                            <span class="material-symbols-rounded text-[32px] text-amber-300">pending_actions</span>
                        </div>
                        <p class="text-sm text-[#9B9A97] font-medium">Tidak ada pesanan menunggu.</p>
                    </div>
                @endforelse
            </div>
        </div>

        {{-- KOLOM 2: SEDANG DIRACIK --}}
        <div class="card-surface overflow-hidden flex flex-col">
            <div class="px-5 py-4 border-b border-[#E9E9E7] flex items-center justify-between bg-blue-50/80">
                <h3 class="font-bold text-[#37352F] flex items-center gap-2.5">
                    <span class="w-2.5 h-2.5 rounded-full bg-blue-500 animate-pulse"></span>
                    Sedang Diracik
                </h3>
                <span class="text-xs font-bold text-blue-700 bg-blue-100 px-2.5 py-1 rounded-lg border border-blue-200">{{ $this->brewingItems->count() }}</span>
            </div>
            <div class="p-4 space-y-3 flex-1 overflow-y-auto max-h-[70vh] custom-scrollbar">
                @forelse($this->brewingItems as $item)
                    <div wire:key="brewing-{{ $item->id }}" class="bg-white rounded-2xl border border-blue-200/80 p-5 shadow-sm hover:shadow-md transition-all duration-200 animate-slide-up-fade">
                        <div class="flex items-start justify-between gap-2 mb-3">
                            <div>
                                <p class="text-[10px] font-black text-blue-600 uppercase tracking-widest bg-blue-50 px-2 py-0.5 rounded-md inline-block border border-blue-200/50">{{ $item->order->order_code }}</p>
                                <p class="text-base font-bold text-[#37352F] mt-1.5">
                                    {{ $item->order->table ? 'Meja '.$item->order->table->table_number : 'Kasir' }}
                                </p>
                            </div>
                            <span class="text-[10px] font-bold text-blue-700 bg-blue-100 border border-blue-200 px-2.5 py-1 rounded-lg flex items-center gap-1">
                                <span class="material-symbols-rounded text-[12px]">person</span>
                                {{ $item->processedBy->name ?? '-' }}
                            </span>
                        </div>

                        <div class="flex items-center gap-2 mb-2">
                            <span class="bg-[#F7F7F5] text-[#37352F] text-xs font-bold px-2 py-1 rounded-lg border border-[#E9E9E7]">{{ $item->qty }}×</span>
                            <span class="text-sm font-bold text-[#37352F]">{{ $item->product->product_name }}</span>
                        </div>

                        @php $slaLevel = $item->slaLevel(); @endphp
                        <div class="mt-3">
                            <span @class([
                                'inline-flex items-center gap-1 text-xs font-bold px-2.5 py-1 rounded-lg',
                                'bg-[#F7F7F5] text-[#787774] border border-[#E9E9E7]' => $slaLevel === 'ok',
                                'bg-amber-100 text-amber-700 border border-amber-200 animate-pulse' => $slaLevel === 'warning',
                                'bg-rose-100 text-rose-700 border border-rose-200 animate-pulse' => $slaLevel === 'critical',
                            ])>
                                <span class="material-symbols-rounded text-[14px]">schedule</span>
                                {{ $item->slaMinutesElapsed() }} menit
                            </span>
                        </div>

                        <div class="flex gap-2 mt-4">
                            <button wire:click="markReady({{ $item->id }})" wire:loading.attr="disabled" wire:key="ready-btn-{{ $item->id }}"
                                    class="flex-1 bg-emerald-600 text-white text-sm font-bold py-3.5 rounded-xl hover:bg-emerald-700 transition-all duration-200 disabled:opacity-50 active:scale-[0.97] shadow-sm flex items-center justify-center gap-2">
                                <span class="material-symbols-rounded text-[18px]">check_circle</span>
                                Selesai
                            </button>
                            <button wire:click="release({{ $item->id }})" wire:loading.attr="disabled" wire:key="release-btn-{{ $item->id }}" title="Lepaskan item ini"
                                    class="px-4 bg-white text-[#37352F] border border-[#E9E9E7] rounded-xl hover:bg-[#F7F7F5] transition-all duration-200 disabled:opacity-50 active:scale-[0.97] shadow-sm">
                                <span class="material-symbols-rounded text-[20px]">undo</span>
                            </button>
                        </div>
                    </div>
                @empty
                    <div class="flex flex-col items-center justify-center py-16 text-center">
                        <div class="w-16 h-16 rounded-2xl bg-blue-50 flex items-center justify-center mb-3 border border-blue-100">
                            <span class="material-symbols-rounded text-[32px] text-blue-300">coffee_maker</span>
                        </div>
                        <p class="text-sm text-[#9B9A97] font-medium">Tidak ada item sedang diracik.</p>
                    </div>
                @endforelse
            </div>
        </div>

        {{-- KOLOM 3: SIAP DIANTAR --}}
        <div class="card-surface overflow-hidden flex flex-col">
            <div class="px-5 py-4 border-b border-[#E9E9E7] flex items-center justify-between bg-emerald-50/80">
                <h3 class="font-bold text-[#37352F] flex items-center gap-2.5">
                    <span class="w-2.5 h-2.5 rounded-full bg-emerald-500"></span>
                    Siap Diantar
                </h3>
                <span class="text-xs font-bold text-emerald-700 bg-emerald-100 px-2.5 py-1 rounded-lg border border-emerald-200">{{ $this->readyItems->count() }}</span>
            </div>
            <div class="p-4 space-y-3 flex-1 overflow-y-auto max-h-[70vh] custom-scrollbar">
                @forelse($this->readyItems as $item)
                    <div wire:key="ready-{{ $item->id }}" class="bg-white rounded-2xl border border-emerald-200/80 p-5 shadow-sm hover:shadow-md transition-all duration-200 animate-slide-up-fade">
                        <p class="text-[10px] font-black text-emerald-600 uppercase tracking-widest bg-emerald-50 px-2 py-0.5 rounded-md inline-block border border-emerald-200/50">{{ $item->order->order_code }}</p>
                        <p class="text-base font-bold text-[#37352F] mt-1.5 mb-2">
                            {{ $item->order->table ? 'Meja '.$item->order->table->table_number : 'Kasir' }}
                        </p>
                        
                        <div class="flex items-center gap-2 mb-2">
                            <span class="bg-[#F7F7F5] text-[#37352F] text-xs font-bold px-2 py-1 rounded-lg border border-[#E9E9E7]">{{ $item->qty }}×</span>
                            <span class="text-sm font-bold text-[#37352F]">{{ $item->product->product_name }}</span>
                        </div>

                        <div class="flex items-center gap-2 mt-3 text-xs text-[#787774]">
                            <span class="material-symbols-rounded text-[14px]">person</span>
                            <span>{{ $item->processedBy->name ?? '-' }}</span>
                            <span class="text-[#C4C3C0]">•</span>
                            <span class="material-symbols-rounded text-[14px]">schedule</span>
                            <span>{{ $item->updated_at->format('H:i') }}</span>
                        </div>
                    </div>
                @empty
                    <div class="flex flex-col items-center justify-center py-16 text-center">
                        <div class="w-16 h-16 rounded-2xl bg-emerald-50 flex items-center justify-center mb-3 border border-emerald-100">
                            <span class="material-symbols-rounded text-[32px] text-emerald-300">check_circle</span>
                        </div>
                        <p class="text-sm text-[#9B9A97] font-medium">Belum ada item selesai hari ini.</p>
                    </div>
                @endforelse
            </div>
        </div>

    </div>
</div>
