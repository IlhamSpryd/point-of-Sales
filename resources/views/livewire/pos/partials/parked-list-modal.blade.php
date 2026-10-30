{{-- MODAL DAFTAR KERANJANG TERTUNDA --}}
<x-ui.modal wire:model="isParkedListOpen" maxWidth="xl">
    <x-slot:header>
        <div class="flex items-center gap-2">
            <span class="material-symbols-rounded text-apeiron-ink text-[22px]">inbox</span>
            <span class="text-base font-bold">Parked Carts</span>
            <span class="bg-gray-100 text-gray-700 text-xs px-2 py-0.5 rounded-full font-bold">
                {{ $this->parkedCarts->count() }}
            </span>
        </div>
    </x-slot:header>

    <div class="space-y-3">
        <p class="text-xs text-gray-500 mb-2">
            List of customer orders currently on hold at this branch.
        </p>

        <div class="space-y-2.5 max-h-[60vh] overflow-y-auto custom-scrollbar pr-1">
            @forelse($this->parkedCarts as $pc)
                @php
                    $pPayload = $pc->cart_payload;
                    $pItems = $pPayload['items'] ?? $pPayload;
                    $pCount = is_array($pItems) ? array_sum(array_column($pItems, 'qty')) : 0;
                @endphp
                <div class="p-3.5 rounded-xl border border-[#E5E7EB] bg-gray-50/60 hover:bg-white hover:border-zinc-400 transition-all flex items-center justify-between gap-3 shadow-xs">
                    <div class="min-w-0">
                        <div class="flex items-center gap-2">
                            <span class="font-bold text-xs text-apeiron-ink">{{ $pc->hold_reference }}</span>
                            <span class="text-[10px] font-bold px-1.5 py-0.5 rounded bg-gray-200 text-gray-700 uppercase">{{ $pc->order_type }}</span>
                        </div>
                        @php
                            $parkedTime = $pc->parked_at
                                ? ($pc->parked_at->format('H:i') . ' (' . $pc->parked_at->diffForHumans() . ')')
                                : ($pc->created_at ? ($pc->created_at->format('H:i') . ' (' . $pc->created_at->diffForHumans() . ')') : '-');
                        @endphp
                        <p class="text-[11px] text-gray-500 mt-1">
                            {{ $pCount }} {{ \Illuminate\Support\Str::plural('item', $pCount) }} &bull; Held {{ $parkedTime }}
                        </p>
                        @if($pc->notes)
                            <p class="text-[11px] text-gray-400 italic mt-0.5">{{ $pc->notes }}</p>
                        @endif
                    </div>

                    <div class="flex items-center gap-1.5 shrink-0">
                        <button type="button" wire:click="resumeCart({{ $pc->id }})"
                            class="px-3 py-1.5 rounded-lg bg-apeiron-ink text-white text-xs font-bold hover:bg-[#111111] shadow-sm flex items-center gap-1 active:scale-95 transition-all">
                            <span class="material-symbols-rounded text-[15px]">play_arrow</span> Resume
                        </button>
                        <button type="button" wire:click="discardHeldCart({{ $pc->id }})"
                            class="p-1.5 rounded-lg border border-rose-200 bg-rose-50 text-rose-600 hover:bg-rose-100 active:scale-95 transition-all" title="Discard Cart">
                            <span class="material-symbols-rounded text-[16px]">delete</span>
                        </button>
                    </div>
                </div>
            @empty
                <div class="py-10 text-center text-gray-400">
                    <span class="material-symbols-rounded text-[36px] text-gray-300 mb-2 block">inbox</span>
                    <p class="text-xs font-semibold">No parked carts at this time.</p>
                </div>
            @endforelse
        </div>
    </div>

    <x-slot:footer>
        <div class="flex justify-end w-full">
            <button type="button" wire:click="$set('isParkedListOpen', false)"
                class="px-4 py-2 rounded-xl border border-[#E5E7EB] text-xs font-semibold text-gray-600 hover:bg-gray-100 transition-colors">
                Close
            </button>
        </div>
    </x-slot:footer>
</x-ui.modal>
