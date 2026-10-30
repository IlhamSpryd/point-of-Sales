<div data-gsap="page-enter">
    @include('inventory.partials.sub-nav')
    {{-- Header & Primary Action --}}
    <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between mb-6 gap-4">
        <div>
            <h4 class="text-xl font-bold text-apeiron-ink tracking-tight">Inter-Branch Stock Transfers</h4>
            <p class="text-sm font-medium text-apeiron-muted mt-1">
                Two-phase inter-branch stock transfers with in-transit accountability (OD-03 & OD-06).
            </p>
        </div>
        <div class="flex flex-col sm:flex-row items-center gap-3 w-full sm:w-auto">
            <x-ui.button type="button" variant="primary" wire:click="openCreateModal" x-on:click="window.erpMotion?.pulseHighlight($el)">
                <span class="material-symbols-rounded text-[18px]">add</span> New Transfer Request
            </x-ui.button>
        </div>
    </div>

    {{-- Notification Alert --}}
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
            <input type="text" wire:model.live.debounce.300ms="search" placeholder="Search transfer number, notes..."
                   class="form-input w-full pl-10 pr-4 py-2 rounded-2xl border border-apeiron-border bg-white text-apeiron-ink placeholder-apeiron-muted focus:outline-none focus:ring-2 focus:ring-apeiron-ink shadow-sm text-sm">
            <div class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none text-apeiron-muted">
                <span class="material-symbols-rounded text-[18px]">search</span>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-3 w-full sm:w-auto">
            <div class="relative w-full sm:w-44">
                <select wire:model.live="directionFilter"
                        class="form-input w-full pl-4 pr-8 py-2 rounded-2xl border border-apeiron-border bg-white text-apeiron-ink focus:outline-none focus:ring-2 focus:ring-apeiron-ink text-sm shadow-sm appearance-none">
                    <option value="ALL">All Directions</option>
                    <option value="OUTBOUND">Outbound (Origin)</option>
                    <option value="INBOUND">Inbound (Destination)</option>
                </select>
                <div class="absolute inset-y-0 right-0 flex items-center px-2 pointer-events-none text-apeiron-muted">
                    <span class="material-symbols-rounded text-[18px]">swap_horiz</span>
                </div>
            </div>

            <div class="relative w-full sm:w-56">
                <select wire:model.live="statusFilter"
                        class="form-input w-full pl-4 pr-8 py-2 rounded-2xl border border-apeiron-border bg-white text-apeiron-ink focus:outline-none focus:ring-2 focus:ring-apeiron-ink text-sm shadow-sm appearance-none">
                    <option value="ALL">All Statuses</option>
                    @foreach ($statusOptions as $s)
                        <option value="{{ $s->value }}">{{ $s->label() }}</option>
                    @endforeach
                </select>
                <div class="absolute inset-y-0 right-0 flex items-center px-2 pointer-events-none text-apeiron-muted">
                    <span class="material-symbols-rounded text-[18px]">keyboard_arrow_down</span>
                </div>
            </div>
        </div>
    </div>

    {{-- Stock Transfers Table --}}
    <div class="card-surface overflow-hidden w-full shrink-0">
        <div class="overflow-x-auto">
            <x-data-table>
                <x-slot:head>
                    <th scope="col" class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider">Transfer #</th>
                    <th scope="col" class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider">Route</th>
                    <th scope="col" class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider text-center">Status</th>
                    <th scope="col" class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider text-right">Items</th>
                    <th scope="col" class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider">Timestamps</th>
                    <th scope="col" class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider text-center">Actions</th>
                </x-slot:head>

                @forelse ($transfers as $transfer)
                    <tr class="hover:bg-apeiron-surface transition-colors duration-150">
                        <td class="px-6 py-4 whitespace-nowrap">
                            <span class="text-sm font-bold text-apeiron-ink">{{ $transfer->transfer_number }}</span>
                            <div class="text-xs text-apeiron-muted">Created by: {{ $transfer->creator->name ?? 'System' }}</div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-apeiron-ink">
                            <div class="flex items-center gap-2">
                                <span class="font-semibold {{ $transfer->from_store_id === $currentStoreId ? 'text-amber-800' : 'text-apeiron-ink' }}">
                                    {{ $transfer->fromStore->name }}
                                </span>
                                <span class="material-symbols-rounded text-apeiron-muted text-[18px]">arrow_forward</span>
                                <span class="font-semibold {{ $transfer->to_store_id === $currentStoreId ? 'text-emerald-800' : 'text-apeiron-ink' }}">
                                    {{ $transfer->toStore->name }}
                                </span>
                            </div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-center">
                            <x-ui.badge :variant="$transfer->status->badgeType()">
                                {{ $transfer->status->label() }}
                            </x-ui.badge>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-semibold text-apeiron-ink text-right">
                            {{ $transfer->items_count }} items
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-xs text-apeiron-ink">
                            @if ($transfer->shipped_at)
                                <div>Shipped: <span class="font-medium text-amber-700">{{ $transfer->shipped_at->format('d M H:i') }}</span></div>
                            @endif
                            @if ($transfer->received_at)
                                <div>Received: <span class="font-medium text-emerald-700">{{ $transfer->received_at->format('d M H:i') }}</span></div>
                            @endif
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-center text-sm font-medium">
                            <div class="flex items-center justify-center gap-2">
                                <button type="button" wire:click="viewDetail({{ $transfer->id }})" class="p-1 hover:text-apeiron-ink text-apeiron-muted transition-colors" title="View Details">
                                    <span class="material-symbols-rounded text-[20px]">visibility</span>
                                </button>

                                @if ($transfer->status === \App\Enums\StockTransferStatusEnum::Draft && $transfer->from_store_id === $currentStoreId)
                                    <button type="button" wire:click="shipTransfer({{ $transfer->id }})" wire:confirm="Dispatch this stock transfer now? Stock at origin branch will be deducted." class="p-1 text-amber-600 hover:text-amber-800 transition-colors" title="Dispatch Goods">
                                        <span class="material-symbols-rounded text-[20px]">local_shipping</span>
                                    </button>
                                @endif

                                @if ($transfer->status === \App\Enums\StockTransferStatusEnum::InTransit && $transfer->to_store_id === $currentStoreId)
                                    <button type="button" wire:click="openReceiveModal({{ $transfer->id }})" class="p-1 text-emerald-600 hover:text-emerald-800 transition-colors" title="Receive Goods at Destination">
                                        <span class="material-symbols-rounded text-[20px]">inventory</span>
                                    </button>
                                @endif

                                @if ($transfer->status->canCancel())
                                    <button type="button" wire:click="cancelTransfer({{ $transfer->id }})" wire:confirm="Cancel this stock transfer? If already in transit, stock will be returned to origin branch." class="p-1 text-rose-500 hover:text-rose-700 transition-colors" title="Cancel Transfer">
                                        <span class="material-symbols-rounded text-[20px]">cancel</span>
                                    </button>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-6 py-8 text-center text-sm text-apeiron-muted">
                            No inter-branch stock transfer documents found.
                        </td>
                    </tr>
                @endforelse
            </x-data-table>
        </div>

        @if ($transfers->hasPages())
            <div class="px-6 py-4 border-t border-apeiron-border">
                {{ $transfers->links() }}
            </div>
        @endif
    </div>

    {{-- MODAL 1: Create Transfer Request Form --}}
    <x-ui.modal wire:model="showFormModal" maxWidth="3xl">
        <x-slot:header>
            <h5 class="text-lg font-bold text-apeiron-ink">New Stock Transfer Request</h5>
        </x-slot:header>
        <x-slot:content>
            @if ($errors->has('general'))
                <x-ui.alert variant="destructive" class="mb-4">
                    {{ $errors->first('general') }}
                </x-ui.alert>
            @endif

            <div class="space-y-4">
                <div>
                    <label class="block text-xs font-bold text-apeiron-ink uppercase tracking-wider mb-1">Destination Branch</label>
                    <select wire:model="to_store_id" class="form-input w-full p-3 rounded-xl border border-apeiron-border text-sm">
                        <option value="">-- Select Destination Branch --</option>
                        @foreach ($destinationStores as $dest)
                            <option value="{{ $dest->id }}">{{ $dest->name }}</option>
                        @endforeach
                    </select>
                    @error('to_store_id') <span class="text-xs text-rose-600 font-medium">{{ $message }}</span> @enderror
                </div>

                {{-- Dynamic Items Table --}}
                <div>
                    <div class="flex items-center justify-between mb-2">
                        <label class="block text-xs font-bold text-apeiron-ink uppercase tracking-wider">Transfer Items</label>
                        <button type="button" wire:click="addItemRow" class="text-xs font-semibold text-apeiron-ink hover:underline flex items-center gap-1">
                            <span class="material-symbols-rounded text-sm">add</span> Add Line
                        </button>
                    </div>

                    <div class="space-y-2 max-h-64 overflow-y-auto">
                        @foreach ($items as $index => $item)
                            <div class="p-3 rounded-2xl bg-apeiron-surface border border-apeiron-border flex flex-col sm:flex-row items-center gap-3 text-sm">
                                <div class="w-full sm:w-32">
                                    <select wire:model.live="items.{{ $index }}.item_type" class="form-input w-full p-2 rounded-lg border border-apeiron-border text-xs">
                                        <option value="product">Product</option>
                                        <option value="ingredient">Raw Material</option>
                                    </select>
                                </div>

                                <div class="w-full sm:flex-1">
                                    @if (($item['item_type'] ?? 'product') === 'product')
                                        <select wire:model="items.{{ $index }}.product_id" class="form-input w-full p-2 rounded-lg border border-apeiron-border text-xs">
                                            <option value="">-- Select Product --</option>
                                            @foreach ($availableProducts as $p)
                                                <option value="{{ $p->id }}">{{ $p->name }} ({{ $p->code ?? '-' }})</option>
                                            @endforeach
                                        </select>
                                    @else
                                        <select wire:model="items.{{ $index }}.ingredient_id" class="form-input w-full p-2 rounded-lg border border-apeiron-border text-xs">
                                            <option value="">-- Select Raw Material --</option>
                                            @foreach ($availableIngredients as $ing)
                                                <option value="{{ $ing->id }}">{{ $ing->name }} ({{ $ing->code ?? '-' }})</option>
                                            @endforeach
                                        </select>
                                    @endif
                                </div>

                                <div class="w-full sm:w-28">
                                    <input type="number" step="0.0001" min="0.0001" wire:model="items.{{ $index }}.quantity_requested" placeholder="Qty" class="form-input w-full p-2 rounded-lg border border-apeiron-border text-xs text-right font-semibold">
                                </div>

                                <button type="button" wire:click="removeItemRow({{ $index }})" class="p-1 text-rose-500 hover:text-rose-700">
                                    <span class="material-symbols-rounded text-[18px]">delete</span>
                                </button>
                            </div>
                        @endforeach
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-bold text-apeiron-ink uppercase tracking-wider mb-1">Notes</label>
                    <textarea wire:model="notes" rows="2" placeholder="Delivery notes, transport details, waybill reference, etc." class="form-input w-full p-3 rounded-xl border border-apeiron-border text-sm"></textarea>
                </div>
            </div>

            <div class="flex items-center justify-end gap-3 mt-6 pt-4 border-t border-apeiron-border">
                <x-ui.button type="button" variant="secondary" wire:click="closeFormModal">Cancel</x-ui.button>
                <x-ui.button type="button" variant="primary" wire:click="saveTransfer" x-on:click="window.erpMotion?.pulseHighlight($el)">Save Transfer Draft</x-ui.button>
            </div>
        </x-slot:content>
    </x-ui.modal>

    {{-- MODAL 2: Transfer Receipt & Discrepancy Resolution (OD-03) --}}
    <x-ui.modal wire:model="showReceiveModal" maxWidth="4xl">
        <x-slot:header>
            @if ($activeTransfer)
                <div>
                    <h5 class="text-lg font-bold text-apeiron-ink">Receive Stock Transfer: {{ $activeTransfer->transfer_number }}</h5>
                    <p class="text-xs text-apeiron-muted">Origin: {{ $activeTransfer->fromStore->name }} &rarr; Destination: {{ $activeTransfer->toStore->name }}</p>
                </div>
            @endif
        </x-slot:header>
        <x-slot:content>
            @if ($activeTransfer)
                <div class="p-3 mb-4 rounded-2xl bg-amber-50 border border-amber-200 text-xs text-amber-900">
                    <strong>Anti-Over-Receipt Policy (OD-03):</strong> Received quantity cannot exceed shipped quantity. If physical count is short, a discrepancy resolution must be designated (return to origin or write off in transit).
                </div>

                <div class="max-h-80 overflow-y-auto mb-4">
                    <table class="table-standard w-full min-w-full text-left text-sm">
                        <thead class="bg-apeiron-surface text-xs font-semibold text-apeiron-muted uppercase">
                            <tr>
                                <th class="p-3">Item</th>
                                <th class="p-3 text-right">Shipped</th>
                                <th class="p-3 text-right w-36">Received</th>
                                <th class="p-3 w-48">Discrepancy Resolution</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-apeiron-border">
                            @foreach ($activeTransfer->items as $item)
                                <tr>
                                    <td class="p-3">
                                        <div class="font-bold text-apeiron-ink">
                                            {{ $item->item_type === 'product' ? ($item->product?->product_name ?? $item->product?->name) : $item->ingredient?->name }}
                                        </div>
                                        <div class="text-xs text-apeiron-muted uppercase">{{ $item->item_type }}</div>
                                    </td>
                                    <td class="p-3 text-right font-semibold tabular-nums text-apeiron-ink">
                                        {{ number_format((float) $item->quantity_shipped, 2) }}
                                    </td>
                                    <td class="p-3 text-right">
                                        <input type="number" step="0.0001" min="0" max="{{ $item->quantity_shipped }}" wire:model.live="receivedQuantities.{{ $item->id }}" class="form-input w-full p-2 text-right tabular-nums rounded-lg border border-apeiron-border text-sm font-semibold">
                                        @error("receivedQuantities.{$item->id}") <span class="text-[10px] text-rose-600 block">{{ $message }}</span> @enderror
                                    </td>
                                    <td class="p-3">
                                        @php
                                            $currReceived = (float) ($receivedQuantities[$item->id] ?? $item->quantity_shipped);
                                            $hasDiscrepancy = $currReceived < (float) $item->quantity_shipped;
                                        @endphp
                                        @if ($hasDiscrepancy)
                                            <select wire:model="discrepancyResolutions.{{ $item->id }}" class="form-input w-full p-2 rounded-lg border border-amber-300 bg-amber-50 text-xs font-medium">
                                                <option value="NONE">-- Select Resolution --</option>
                                                <option value="RETURN_TO_SOURCE">Return to Origin</option>
                                                <option value="WRITE_OFF_IN_TRANSIT">Write-off In Transit</option>
                                            </select>
                                        @else
                                            <span class="text-xs text-emerald-600 font-medium flex items-center gap-1">
                                                <span class="material-symbols-rounded text-sm">check</span> Complete
                                            </span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div>
                    <label class="block text-xs font-bold text-apeiron-ink uppercase tracking-wider mb-1">Receiving Notes</label>
                    <input type="text" wire:model="receiveNotes" placeholder="Condition of goods upon arrival..." class="form-input w-full p-2 rounded-xl border border-apeiron-border text-xs">
                </div>

                <div class="flex items-center justify-end gap-3 mt-6 pt-4 border-t border-apeiron-border">
                    <x-ui.button type="button" variant="secondary" wire:click="closeReceiveModal">Cancel</x-ui.button>
                    <x-ui.button type="button" variant="primary" wire:click="submitReceive" x-on:click="window.erpMotion?.pulseHighlight($el)">Confirm Goods Receipt</x-ui.button>
                </div>
            @endif
        </x-slot:content>
    </x-ui.modal>

    {{-- MODAL 3: Transfer Document Details --}}
    <x-ui.modal wire:model="showDetailModal" maxWidth="4xl">
        <x-slot:header>
            @if ($selectedTransfer)
                <div>
                    <h5 class="text-lg font-bold text-apeiron-ink">Transfer Details: {{ $selectedTransfer->transfer_number }}</h5>
                    <p class="text-xs text-apeiron-muted">Status: {{ $selectedTransfer->status->label() }}</p>
                </div>
            @endif
        </x-slot:header>
        <x-slot:content>
            @if ($selectedTransfer)
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-6 p-4 rounded-2xl bg-apeiron-surface text-xs">
                    <div>
                        <span class="text-apeiron-muted block">Origin Branch</span>
                        <span class="font-semibold text-apeiron-ink">{{ $selectedTransfer->fromStore->name }}</span>
                    </div>
                    <div>
                        <span class="text-apeiron-muted block">Destination Branch</span>
                        <span class="font-semibold text-apeiron-ink">{{ $selectedTransfer->toStore->name }}</span>
                    </div>
                    <div>
                        <span class="text-apeiron-muted block">Shipped At</span>
                        <span class="font-semibold text-apeiron-ink">{{ $selectedTransfer->shipped_at?->format('d M Y H:i') ?? '-' }}</span>
                    </div>
                    <div>
                        <span class="text-apeiron-muted block">Received At</span>
                        <span class="font-semibold text-apeiron-ink">{{ $selectedTransfer->received_at?->format('d M Y H:i') ?? '-' }}</span>
                    </div>
                </div>

                <div class="max-h-80 overflow-y-auto mb-4">
                    <table class="table-standard w-full min-w-full text-left text-sm">
                        <thead class="bg-apeiron-surface text-xs font-semibold text-apeiron-muted uppercase">
                            <tr>
                                <th class="p-3">Item</th>
                                <th class="p-3 text-right">Requested</th>
                                <th class="p-3 text-right">Shipped</th>
                                <th class="p-3 text-right">Received</th>
                                <th class="p-3 text-right">Carrying Cost</th>
                                <th class="p-3 text-center">Discrepancy Resolution</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-apeiron-border">
                            @foreach ($selectedTransfer->items as $item)
                                <tr>
                                    <td class="p-3">
                                        <div class="font-bold text-apeiron-ink">
                                            {{ $item->item_type === 'product' ? ($item->product?->product_name ?? $item->product?->name) : $item->ingredient?->name }}
                                        </div>
                                        <div class="text-xs text-apeiron-muted uppercase">{{ $item->item_type }}</div>
                                    </td>
                                    <td class="p-3 text-right font-medium tabular-nums text-apeiron-ink">{{ number_format((float) $item->quantity_requested, 2) }}</td>
                                    <td class="p-3 text-right font-medium tabular-nums text-apeiron-ink">{{ number_format((float) $item->quantity_shipped, 2) }}</td>
                                    <td class="p-3 text-right font-semibold tabular-nums text-apeiron-ink">{{ $item->quantity_received !== null ? number_format((float) $item->quantity_received, 2) : '-' }}</td>
                                    <td class="p-3 text-right text-xs font-mono tabular-nums">Rp {{ number_format((float) $item->carrying_unit_cost, 0, ',', '.') }}</td>
                                    <td class="p-3 text-center text-xs">
                                        <span class="px-2 py-1 rounded bg-apeiron-surface border border-apeiron-border">
                                            {{ $item->discrepancy_resolution->value }}
                                        </span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="flex items-center justify-end gap-3 pt-4 border-t border-apeiron-border">
                    <x-ui.button type="button" variant="secondary" wire:click="closeDetailModal">Close</x-ui.button>
                </div>
            @endif
        </x-slot:content>
    </x-ui.modal>
</div>
