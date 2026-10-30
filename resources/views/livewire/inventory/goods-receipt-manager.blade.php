<div data-gsap="page-enter">
    @include('inventory.partials.sub-nav')
    {{-- Header & Aksi Utama --}}
    <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between mb-6 gap-4">
        <div>
            <h4 class="text-xl font-bold text-apeiron-ink tracking-tight">Goods Receipt</h4>
            <p class="text-sm font-medium text-apeiron-muted mt-1">
                Record physical receipt of products & ingredients, recalculate WAC valuation, and capture lot batch/expiry details
            </p>
        </div>
        <div class="flex flex-col sm:flex-row items-center gap-3 w-full sm:w-auto">
            <x-ui.button type="button" variant="primary" wire:click="openCreateModal" x-on:click="window.erpMotion?.pulseHighlight($el)">
                <span class="material-symbols-rounded text-[18px]">add</span> New Goods Receipt
            </x-ui.button>
        </div>
    </div>

    {{-- Alert Notifikasi --}}
    @if (session()->has('message'))
        <x-ui.alert variant="success" class="mb-4">
            {{ session('message') }}
        </x-ui.alert>
    @endif

    @if (session()->has('error'))
        <x-ui.alert variant="destructive" class="mb-4">
            {{ session('error') }}
        </x-ui.alert>
    @endif

    {{-- Filter & Search Bar --}}
    <div class="flex flex-col sm:flex-row items-center justify-between gap-4 mb-4">
        <div class="relative w-full sm:w-80">
            <input type="text" wire:model.live.debounce.300ms="search" placeholder="Search GRN number, supplier, PO..."
                   class="form-input w-full pl-10 pr-4 py-2 rounded-2xl border border-apeiron-border bg-white text-apeiron-ink placeholder-apeiron-muted focus:outline-none focus:ring-2 focus:ring-apeiron-ink shadow-sm text-sm">
            <div class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none text-apeiron-muted">
                <span class="material-symbols-rounded text-[18px]">search</span>
            </div>
        </div>

        <div class="flex items-center gap-3 w-full sm:w-auto">
            <div class="relative w-full sm:w-48">
                <select wire:model.live="statusFilter"
                        class="form-input w-full pl-4 pr-8 py-2 rounded-2xl border border-apeiron-border bg-white text-apeiron-ink focus:outline-none focus:ring-2 focus:ring-apeiron-ink text-sm shadow-sm appearance-none">
                    <option value="ALL">All Statuses</option>
                    <option value="DRAFT">Draft</option>
                    <option value="POSTED">Posted</option>
                    <option value="REVERSED">Reversed</option>
                    <option value="CANCELLED">Cancelled</option>
                </select>
                <div class="absolute inset-y-0 right-0 flex items-center px-2 pointer-events-none text-apeiron-muted">
                    <span class="material-symbols-rounded text-[18px]">keyboard_arrow_down</span>
                </div>
            </div>
        </div>
    </div>

    {{-- Tabel Goods Receipts --}}
    <div class="card-surface overflow-hidden w-full shrink-0">
        <div class="overflow-x-auto">
            <x-data-table>
                <x-slot:head>
                    <th scope="col" class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider">Receipt No.</th>
                    <th scope="col" class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider">Received At</th>
                    <th scope="col" class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider">Supplier / PO</th>
                    <th scope="col" class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider text-center">Status</th>
                    <th scope="col" class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider text-right">Items</th>
                    <th scope="col" class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider text-right">Total Value</th>
                    <th scope="col" class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider text-center">Actions</th>
                </x-slot:head>

                @forelse ($receipts as $receipt)
                    <tr class="hover:bg-apeiron-surface transition-colors duration-150">
                        <td class="px-6 py-4 whitespace-nowrap">
                            <span class="text-sm font-bold text-apeiron-ink">{{ $receipt->receipt_number }}</span>
                            <div class="text-xs text-apeiron-muted">Created by: {{ $receipt->creator->name ?? 'System' }}</div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-apeiron-ink">
                            {{ $receipt->received_at->format('d M Y H:i') }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-apeiron-ink">
                            <div>{{ $receipt->supplier_reference ?: '-' }}</div>
                            @if($receipt->purchase_order_reference)
                                <div class="text-xs text-apeiron-muted">PO: {{ $receipt->purchase_order_reference }}</div>
                            @endif
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-center">
                            <x-ui.badge :variant="$receipt->status->badgeType()">
                                {{ $receipt->status->label() }}
                            </x-ui.badge>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-semibold text-apeiron-ink">
                            {{ $receipt->items->count() }} items
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-bold text-apeiron-ink">
                            Rp {{ number_format((float) $receipt->calculateTotalValue(), 2, ',', '.') }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-center text-sm font-medium space-x-2">
                            <button wire:click="viewDetails({{ $receipt->id }})" class="text-apeiron-ink hover:text-apeiron-muted transition-colors" title="View Details">
                                <span class="material-symbols-rounded text-[18px]">visibility</span>
                            </button>

                            @if($receipt->isDraft())
                                <button wire:click="post({{ $receipt->id }})" wire:confirm="Are you sure you want to post this goods receipt to warehouse inventory?" class="text-emerald-600 hover:text-emerald-800 transition-colors" title="Post to Inventory">
                                    <span class="material-symbols-rounded text-[18px]">task_alt</span>
                                </button>
                                <button wire:click="cancel({{ $receipt->id }})" wire:confirm="Cancel this draft goods receipt?" class="text-rose-500 hover:text-rose-700 transition-colors" title="Cancel Draft">
                                    <span class="material-symbols-rounded text-[18px]">cancel</span>
                                </button>
                            @elseif($receipt->isPosted())
                                <button wire:click="openReverseModal({{ $receipt->id }})" class="text-rose-600 hover:text-rose-800 transition-colors" title="Reverse Document">
                                    <span class="material-symbols-rounded text-[18px]">undo</span>
                                </button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-6 py-8 text-center text-sm text-apeiron-muted">
                            No goods receipt documents match the selected criteria.
                        </td>
                    </tr>
                @endforelse
            </x-data-table>
        </div>
        @if ($receipts->hasPages())
            <div class="p-4 border-t border-apeiron-border">
                {{ $receipts->links() }}
            </div>
        @endif
    </div>

    {{-- Modal Buat / Edit Draft --}}
    <x-ui.modal wire:model="showFormModal" maxWidth="4xl">
        <x-slot:header>
            <h3 class="text-lg font-bold text-apeiron-ink">
                {{ $isEditing ? 'Edit Goods Receipt Draft' : 'New Goods Receipt' }}
            </h3>
        </x-slot:header>
        <x-slot:content>
            <div class="py-2 space-y-4">
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-xs font-semibold text-apeiron-muted mb-1">Supplier Reference</label>
                        <input type="text" wire:model="supplier_reference" placeholder="Supplier Name / Delivery Note No."
                               class="form-input w-full px-3 py-2 rounded-xl border border-apeiron-border text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-apeiron-muted mb-1">PO No. (Optional)</label>
                        <input type="text" wire:model="purchase_order_reference" placeholder="PO-XXXX"
                               class="form-input w-full px-3 py-2 rounded-xl border border-apeiron-border text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-apeiron-muted mb-1">Receipt Date & Time *</label>
                        <input type="datetime-local" wire:model="received_at"
                               class="form-input w-full px-3 py-2 rounded-xl border border-apeiron-border text-sm">
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-apeiron-muted mb-1">Notes</label>
                    <textarea wire:model="notes" rows="2" placeholder="Receipt notes..."
                              class="form-input w-full px-3 py-2 rounded-xl border border-apeiron-border text-sm"></textarea>
                </div>

                {{-- Daftar Baris Item --}}
                <div class="pt-2">
                    <div class="flex items-center justify-between mb-2">
                        <h5 class="text-sm font-bold text-apeiron-ink">Received Items</h5>
                        <button type="button" wire:click="addItemLine" class="text-xs font-semibold text-emerald-600 hover:text-emerald-800 flex items-center gap-1">
                            <span class="material-symbols-rounded text-[16px]">add_circle</span> Add Row
                        </button>
                    </div>

                    <div class="space-y-3">
                        @foreach ($items as $index => $item)
                            <div class="p-3 rounded-2xl border border-apeiron-border bg-slate-50/50 space-y-2">
                                <div class="grid grid-cols-12 gap-2 items-center">
                                    <div class="col-span-12 sm:col-span-2">
                                        <select wire:model.live="items.{{ $index }}.item_type" class="w-full text-xs py-1.5 px-2 rounded-lg border border-apeiron-border bg-white">
                                            <option value="product">Product</option>
                                            <option value="ingredient">Raw Material</option>
                                        </select>
                                    </div>

                                    <div class="col-span-12 sm:col-span-4">
                                        @if ($item['item_type'] === 'product')
                                            <select wire:model="items.{{ $index }}.product_id" class="w-full text-xs py-1.5 px-2 rounded-lg border border-apeiron-border bg-white">
                                                <option value="">Select Product...</option>
                                                @foreach ($products as $prod)
                                                    <option value="{{ $prod->id }}">{{ $prod->product_name }} ({{ $prod->product_code ?: 'No Code' }})</option>
                                                @endforeach
                                            </select>
                                        @else
                                            <select wire:model="items.{{ $index }}.ingredient_id" class="w-full text-xs py-1.5 px-2 rounded-lg border border-apeiron-border bg-white">
                                                <option value="">Select Raw Material...</option>
                                                @foreach ($ingredients as $ing)
                                                    <option value="{{ $ing->id }}">{{ $ing->name }} ({{ $ing->unit }})</option>
                                                @endforeach
                                            </select>
                                        @endif
                                    </div>

                                    <div class="col-span-6 sm:col-span-2">
                                        <input type="number" step="0.0001" min="0.0001" wire:model="items.{{ $index }}.quantity_received" placeholder="Qty" title="Quantity Received"
                                               class="w-full text-xs py-1.5 px-2 rounded-lg border border-apeiron-border bg-white text-right">
                                    </div>

                                    <div class="col-span-6 sm:col-span-3">
                                        <input type="number" step="0.0001" min="0" wire:model="items.{{ $index }}.unit_cost" placeholder="Cost/Unit" title="Inbound Unit Cost"
                                               class="w-full text-xs py-1.5 px-2 rounded-lg border border-apeiron-border bg-white text-right">
                                    </div>

                                    <div class="col-span-12 sm:col-span-1 text-right">
                                        <button type="button" wire:click="removeItemLine({{ $index }})" class="text-rose-500 hover:text-rose-700">
                                            <span class="material-symbols-rounded text-[18px]">delete</span>
                                        </button>
                                    </div>
                                </div>

                                {{-- OD-02 Lot Receipt Annotation --}}
                                <div class="grid grid-cols-12 gap-2 text-xs">
                                    <div class="col-span-12 sm:col-span-6">
                                        <input type="text" wire:model="items.{{ $index }}.batch_number" placeholder="Batch / Lot No. (Optional)"
                                               class="w-full text-xs py-1 px-2 rounded-lg border border-apeiron-border bg-white placeholder-slate-400">
                                    </div>
                                    <div class="col-span-12 sm:col-span-6">
                                        <input type="date" wire:model="items.{{ $index }}.expiry_date" placeholder="Expiry Date"
                                               class="w-full text-xs py-1 px-2 rounded-lg border border-apeiron-border bg-white text-slate-700">
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>

            <div class="pt-4 border-t border-apeiron-border flex items-center justify-end gap-3">
                <x-ui.button type="button" variant="secondary" wire:click="$set('showFormModal', false)">
                    Cancel
                </x-ui.button>
                <x-ui.button type="button" variant="primary" wire:click="saveDraft" x-on:click="window.erpMotion?.pulseHighlight($el)">
                    Save Draft
                </x-ui.button>
            </div>
        </x-slot:content>
    </x-ui.modal>

    {{-- Modal Detail Penerimaan --}}
    <x-ui.modal wire:model="showDetailModal" maxWidth="4xl">
        <x-slot:header>
            @if ($selectedReceipt)
                <div>
                    <h3 class="text-lg font-bold text-apeiron-ink">Receipt Details: {{ $selectedReceipt->receipt_number }}</h3>
                    <p class="text-xs text-apeiron-muted">Branch: {{ $selectedReceipt->store->name ?? '-' }} | Created: {{ $selectedReceipt->created_at->format('d M Y H:i') }}</p>
                </div>
            @endif
        </x-slot:header>
        <x-slot:content>
            @if ($selectedReceipt)
                <div class="py-2 space-y-4">
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 p-4 rounded-2xl bg-slate-50 text-xs">
                        <div>
                            <span class="text-apeiron-muted block">Status:</span>
                            <x-ui.badge :variant="$selectedReceipt->status->badgeType()">
                                {{ $selectedReceipt->status->label() }}
                            </x-ui.badge>
                        </div>
                        <div>
                            <span class="text-apeiron-muted block">Supplier:</span>
                            <span class="font-semibold text-apeiron-ink">{{ $selectedReceipt->supplier_reference ?: '-' }}</span>
                        </div>
                        <div>
                            <span class="text-apeiron-muted block">PO No.:</span>
                            <span class="font-semibold text-apeiron-ink">{{ $selectedReceipt->purchase_order_reference ?: '-' }}</span>
                        </div>
                        <div>
                            <span class="text-apeiron-muted block">Received At:</span>
                            <span class="font-semibold text-apeiron-ink">{{ $selectedReceipt->received_at->format('d M Y H:i') }}</span>
                        </div>
                    </div>

                    @if($selectedReceipt->notes)
                        <div class="text-xs text-apeiron-ink p-3 rounded-xl bg-slate-50 border border-apeiron-border">
                            <span class="font-semibold block text-apeiron-muted">Notes:</span>
                            {{ $selectedReceipt->notes }}
                        </div>
                    @endif

                    @if($selectedReceipt->isReversed())
                        <div class="text-xs text-rose-800 p-3 rounded-xl bg-rose-50 border border-rose-200">
                            <span class="font-bold block">Reversed by: {{ $selectedReceipt->reverser->name ?? 'User' }} at {{ $selectedReceipt->reversed_at?->format('d M Y H:i') }}</span>
                            Reason: {{ $selectedReceipt->reversal_reason }}
                        </div>
                    @endif

                    <div class="overflow-x-auto border border-apeiron-border rounded-2xl">
                        <table class="table-standard min-w-full divide-y divide-apeiron-border text-xs">
                            <thead class="bg-slate-50 text-apeiron-muted uppercase">
                                <tr>
                                    <th class="px-4 py-3 text-left">Type</th>
                                    <th class="px-4 py-3 text-left">Item Name</th>
                                    <th class="px-4 py-3 text-right">Quantity</th>
                                    <th class="px-4 py-3 text-right">Unit Cost</th>
                                    <th class="px-4 py-3 text-right">Subtotal</th>
                                    <th class="px-4 py-3 text-left">Batch / Expiry</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-apeiron-border">
                                @foreach ($selectedReceipt->items as $item)
                                    <tr>
                                        <td class="px-4 py-2 font-medium capitalize">{{ $item->item_type }}</td>
                                        <td class="px-4 py-2 font-semibold text-apeiron-ink">{{ $item->item_name }}</td>
                                        <td class="px-4 py-2 text-right font-medium tabular-nums">{{ number_format((float) $item->quantity_received, 4) }}</td>
                                        <td class="px-4 py-2 text-right font-medium tabular-nums">Rp {{ number_format((float) $item->unit_cost, 2, ',', '.') }}</td>
                                        <td class="px-4 py-2 text-right font-bold text-apeiron-ink tabular-nums">Rp {{ number_format((float) $item->subtotal, 2, ',', '.') }}</td>
                                        <td class="px-4 py-2 text-apeiron-muted">
                                            @if($item->batch_number) Batch: {{ $item->batch_number }}<br>@endif
                                            @if($item->expiry_date) Exp: {{ $item->expiry_date->format('d/m/Y') }}@endif
                                            @if(!$item->batch_number && !$item->expiry_date) - @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot class="bg-slate-50 font-bold">
                                <tr>
                                    <td colspan="4" class="px-4 py-3 text-right text-apeiron-ink uppercase">Total Document Value:</td>
                                    <td class="px-4 py-3 text-right text-apeiron-ink tabular-nums">Rp {{ number_format((float) $selectedReceipt->calculateTotalValue(), 2, ',', '.') }}</td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>

                <div class="pt-4 border-t border-apeiron-border flex justify-end">
                    <x-ui.button type="button" variant="secondary" wire:click="$set('showDetailModal', false)">
                        Close
                    </x-ui.button>
                </div>
            @endif
        </x-slot:content>
    </x-ui.modal>

    {{-- Modal Reversal --}}
    <x-ui.modal wire:model="showReverseModal" maxWidth="md">
        <x-slot:header>
            <h3 class="text-lg font-bold text-rose-700">Goods Receipt Reversal</h3>
        </x-slot:header>
        <x-slot:content>
            <div class="py-2 space-y-3">
                <div class="p-3 bg-amber-50 rounded-xl border border-amber-200 text-xs text-amber-800">
                    <p class="font-bold mb-1">Notice (Inventory Rule OD-05):</p>
                    This reversal will deduct the warehouse stock balance corresponding to the received quantities. If current on-hand stock is lower than received quantities, the reversal will be rejected.
                </div>

                <div>
                    <label class="block text-xs font-semibold text-apeiron-muted mb-1">Reversal Reason *</label>
                    <textarea wire:model="reversal_reason" rows="3" placeholder="Explain the reason for reversal..."
                              class="form-input w-full px-3 py-2 rounded-xl border border-apeiron-border text-sm"></textarea>
                    @error('reversal_reason') <span class="text-xs text-rose-600">{{ $message }}</span> @enderror
                </div>
            </div>

            <div class="pt-4 border-t border-apeiron-border flex items-center justify-end gap-3">
                <x-ui.button type="button" variant="secondary" wire:click="$set('showReverseModal', false)">
                    Cancel
                </x-ui.button>
                <x-ui.button type="button" variant="destructive" wire:click="submitReversal" x-on:click="window.erpMotion?.pulseHighlight($el)">
                    Confirm Reversal
                </x-ui.button>
            </div>
        </x-slot:content>
    </x-ui.modal>
</div>
