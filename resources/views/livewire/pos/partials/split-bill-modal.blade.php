{{-- MODAL SPLIT BILL (PISAH TAGIHAN F&B) (TASK-P1-01) --}}
@if ($showSplitBillModal)
    <x-ui.modal
        :open="true"
        id="split-bill-modal"
        maxWidth="2xl"
        onClose="$wire.closeSplitBillModal()"
    >
        <x-slot:header>
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-muted border border-border flex items-center justify-center text-foreground">
                    <span class="material-symbols-rounded text-[24px]">call_split</span>
                </div>
                <div>
                    <h3 id="split-bill-modal-title" class="text-base font-semibold text-foreground">
                        Split Bill (Pisah Tagihan Meja)
                    </h3>
                    <p class="text-xs text-muted-foreground">Pecah pesanan aktif menjadi beberapa sub-order terpisah</p>
                </div>
            </div>
        </x-slot:header>

        <!-- Body Modal -->
        <div class="space-y-4 text-xs py-2">
            <!-- Mode Selector Tabs -->
            <div class="flex items-center gap-2 border-b border-border pb-2">
                <button type="button"
                    wire:click="$set('splitBillMode', 'equal')"
                    class="px-3.5 py-1.5 rounded-lg text-xs font-semibold transition-colors {{ $splitBillMode === 'equal' ? 'bg-primary text-primary-foreground shadow-xs' : 'text-muted-foreground hover:text-foreground bg-muted' }}">
                    Bagi Rata (Equal Split)
                </button>
                <button type="button"
                    wire:click="$set('splitBillMode', 'amount')"
                    class="px-3.5 py-1.5 rounded-lg text-xs font-semibold transition-colors {{ $splitBillMode === 'amount' ? 'bg-primary text-primary-foreground shadow-xs' : 'text-muted-foreground hover:text-foreground bg-muted' }}">
                    Bagi Nominal Tertentu
                </button>
                <button type="button"
                    wire:click="$set('splitBillMode', 'item')"
                    class="px-3.5 py-1.5 rounded-lg text-xs font-semibold transition-colors {{ $splitBillMode === 'item' ? 'bg-primary text-primary-foreground shadow-xs' : 'text-muted-foreground hover:text-foreground bg-muted' }}">
                    Pilih Berdasarkan Menu (Split by Item)
                </button>
            </div>

            @if ($this->splitBillOrder)
                <div class="p-3 bg-muted rounded-xl border border-border flex items-center justify-between text-xs">
                    <div>
                        <span class="font-bold text-foreground">Pesanan: #{{ $this->splitBillOrder->order_code }}</span>
                        <span class="text-muted-foreground ml-2">({{ $this->splitBillOrder->table?->table_name ?? 'Dine-In' }})</span>
                    </div>
                    <div class="font-bold text-foreground font-sans tabular-nums text-sm">
                        Total: Rp {{ number_format((float) $this->splitBillOrder->order_amount, 0, ',', '.') }}
                    </div>
                </div>

                @if ($splitBillMode === 'equal')
                    <div class="space-y-3">
                        <label class="block text-xs font-semibold text-foreground uppercase tracking-wider">Jumlah Tagihan Dibagi</label>
                        <div class="flex items-center gap-2">
                            @foreach ([2, 3, 4, 5] as $parts)
                                <button type="button"
                                    wire:click="setSplitBillParts({{ $parts }})"
                                    class="px-4 py-2 rounded-lg border text-xs font-bold font-sans tabular-nums transition-colors {{ $splitBillParts === $parts ? 'border-primary bg-primary text-primary-foreground' : 'border-border bg-background hover:bg-muted text-foreground' }}">
                                    {{ $parts }} Bagian
                                </button>
                            @endforeach
                        </div>
                        <div class="mt-4 p-3 bg-card border border-border rounded-xl space-y-2">
                            <span class="text-xs font-semibold text-muted-foreground block">Estimasi Per Tagihan:</span>
                            @php
                                $totalOrder = (float) $this->splitBillOrder->order_amount;
                                $partAmount = floor($totalOrder / max(2, $splitBillParts));
                                $remainder = $totalOrder - ($partAmount * ($splitBillParts - 1));
                            @endphp
                            @for ($p = 1; $p <= $splitBillParts; $p++)
                                <div class="flex justify-between items-center text-xs py-1 border-b border-border last:border-0">
                                    <span class="font-medium text-foreground">Sub-Order {{ $p }}</span>
                                    <span class="font-bold font-sans tabular-nums text-foreground">
                                        Rp {{ number_format($p === 1 ? $remainder : $partAmount, 0, ',', '.') }}
                                    </span>
                                </div>
                            @endfor
                        </div>
                    </div>
                @elseif ($splitBillMode === 'amount')
                    <div class="space-y-3">
                        <div class="flex justify-between items-center">
                            <label class="text-xs font-semibold text-foreground uppercase tracking-wider">Rincian Nominal Sub-Order</label>
                            <button type="button" wire:click="addSplitBillAmountPart" class="text-xs text-primary font-semibold hover:underline flex items-center gap-1">
                                <span class="material-symbols-rounded text-[16px]">add</span> Tambah Bagian
                            </button>
                        </div>
                        <div class="space-y-2 max-h-48 overflow-y-auto">
                            @foreach ($splitBillAmounts as $idx => $amt)
                                <div class="flex items-center gap-2">
                                    <span class="text-xs font-semibold text-muted-foreground w-20">Bagian {{ $idx + 1 }}:</span>
                                    <input type="number" step="100" min="1"
                                        wire:model.live="splitBillAmounts.{{ $idx }}"
                                        placeholder="Nominal Rp"
                                        class="form-input flex-1 p-2 rounded-lg border border-border text-xs font-semibold font-sans tabular-nums">
                                    @if (count($splitBillAmounts) > 2)
                                        <button type="button" wire:click="removeSplitBillAmountPart({{ $idx }})" class="text-muted-foreground hover:text-rose-600 p-1">
                                            <span class="material-symbols-rounded text-[18px]">delete</span>
                                        </button>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </div>
                @elseif ($splitBillMode === 'item')
                    <div class="space-y-3">
                        <label class="block text-xs font-semibold text-foreground uppercase tracking-wider">Alokasikan Item ke Sub-Order (Part 1 vs Part 2)</label>
                        <div class="space-y-2 max-h-56 overflow-y-auto">
                            @foreach ($this->splitBillOrder->orderItems as $item)
                                <div class="p-2.5 rounded-lg border border-border bg-background flex items-center justify-between gap-3">
                                    <div>
                                        <span class="font-bold text-foreground block">{{ $item->product_name_snapshot ?? $item->product?->product_name }}</span>
                                        <span class="text-muted-foreground text-[11px] font-sans tabular-nums">
                                            {{ $item->qty }}x @ Rp {{ number_format((float) $item->unit_price, 0, ',', '.') }}
                                        </span>
                                    </div>
                                    <select wire:model.live="splitBillItemAssignments.{{ $item->id }}"
                                        class="form-select text-xs p-1.5 rounded-lg border border-border bg-muted text-foreground font-semibold">
                                        <option value="0">Alokasikan ke Sub-Order 1</option>
                                        <option value="1">Alokasikan ke Sub-Order 2</option>
                                    </select>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            @else
                <div class="p-6 text-center text-muted-foreground">
                    <span class="material-symbols-rounded text-3xl block mb-2 text-muted-foreground/50">receipt_long</span>
                    Belum ada pesanan aktif atau pending yang dapat dipecah tagihannya.
                </div>
            @endif
        </div>

        <x-slot:footer>
            <div class="flex justify-between items-center w-full">
                <x-ui.button variant="secondary" wire:click="closeSplitBillModal">
                    Tutup
                </x-ui.button>
                @if ($this->splitBillOrder)
                    <x-ui.button variant="primary" wire:click="executeSplitBill" x-on:click="window.erpMotion?.pulseHighlight($el)">
                        Proses Split Bill
                    </x-ui.button>
                @endif
            </div>
        </x-slot:footer>
    </x-ui.modal>
@endif
