<div data-gsap="page-enter">
    @include('inventory.partials.sub-nav')
    
    <x-erp.page-header
        title="Inter-Branch Stock Transfers"
        description="Two-phase inter-branch stock transfers with in-transit accountability (OD-03 & OD-06)."
    >
        <x-slot:actions>
            <x-ui.button type="button" variant="primary" wire:click="openCreateModal" x-on:click="window.erpMotion?.pulseHighlight($el)">
                <span class="material-symbols-rounded text-[20px]">add</span> New Transfer Request
            </x-ui.button>
        </x-slot:actions>
    </x-erp.page-header>

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
    <x-erp.filter-bar
        search-placeholder="Search transfer number, notes..."
        search-model="search"
        :reset-method="null"
    >
        <x-slot:filters>
            <select wire:model.live="directionFilter"
                    class="h-9 px-3 rounded-medium border border-input bg-background text-foreground text-xs font-semibold focus-visible:ring-1 focus-visible:ring-ring outline-none">
                <option value="ALL">All Directions</option>
                <option value="OUTBOUND">Outbound (Origin)</option>
                <option value="INBOUND">Inbound (Destination)</option>
            </select>

            <select wire:model.live="statusFilter"
                    class="h-9 px-3 rounded-medium border border-input bg-background text-foreground text-xs font-semibold focus-visible:ring-1 focus-visible:ring-ring outline-none">
                <option value="ALL">All Statuses</option>
                @foreach ($statusOptions as $s)
                    <option value="{{ $s->value }}">{{ $s->label() }}</option>
                @endforeach
            </select>
        </x-slot:filters>
    </x-erp.filter-bar>

    {{-- Stock Transfers Table --}}
    <x-erp.data-table
        loading-target="search, statusFilter"
        :is-empty="$transfers->isEmpty()"
        empty-title="No inter-branch stock transfer documents found"
        empty-description="Create a new transfer request to move items between branches."
        empty-icon="local_shipping"
        :colspan="6"
    >
                <x-slot:head>
                    <th scope="col" class="px-6 py-4 text-xs font-semibold text-muted-foreground uppercase tracking-wider">Transfer #</th>
                    <th scope="col" class="px-6 py-4 text-xs font-semibold text-muted-foreground uppercase tracking-wider">Route</th>
                    <th scope="col" class="px-6 py-4 text-xs font-semibold text-muted-foreground uppercase tracking-wider text-center">Status</th>
                    <th scope="col" class="px-6 py-4 text-xs font-semibold text-muted-foreground uppercase tracking-wider text-right font-sans tabular-nums numeric">Items</th>
                    <th scope="col" class="px-6 py-4 text-xs font-semibold text-muted-foreground uppercase tracking-wider">Timestamps</th>
                    <th scope="col" class="px-6 py-4 text-xs font-semibold text-muted-foreground uppercase tracking-wider text-right">Actions</th>
                </x-slot:head>

                @foreach ($transfers as $transfer)
                    <tr class="hover:bg-secondary transition-colors duration-150">
                        <td class="px-6 py-4 whitespace-nowrap">
                            <span class="text-sm font-bold text-foreground">{{ $transfer->transfer_number }}</span>
                            <div class="text-xs text-muted-foreground">Created by: {{ $transfer->creator->name ?? 'System' }}</div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-foreground">
                            <div class="flex items-center gap-2">
                                <span class="font-semibold {{ $transfer->from_store_id === $currentStoreId ? 'text-amber-800' : 'text-foreground' }}">
                                    {{ $transfer->fromStore->name }}
                                </span>
                                <span class="material-symbols-rounded text-muted-foreground text-[20px]">arrow_forward</span>
                                <span class="font-semibold {{ $transfer->to_store_id === $currentStoreId ? 'text-emerald-800' : 'text-foreground' }}">
                                    {{ $transfer->toStore->name }}
                                </span>
                            </div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            @php
                                $transferDot = match($transfer->status->badgeType()) {
                                    'success' => 'bg-success',
                                    'warning' => 'bg-warning',
                                    'danger' => 'bg-destructive',
                                    default => 'bg-neutral',
                                };
                            @endphp
                            <span class="inline-flex items-center gap-1.5 text-xs text-foreground font-medium">
                                <span class="w-1.5 h-1.5 rounded-full {{ $transferDot }}"></span>
                                {{ $transfer->status->label() }}
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-semibold text-foreground text-right">
                            {{ $transfer->items_count }} items
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-xs text-foreground">
                            @if ($transfer->shipped_at)
                                <div>Shipped: <span class="font-medium text-amber-700">{{ $transfer->shipped_at->format('d M H:i') }}</span></div>
                            @endif
                            @if ($transfer->received_at)
                                <div>Received: <span class="font-medium text-emerald-700">{{ $transfer->received_at->format('d M H:i') }}</span></div>
                            @endif
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right">
                            <x-ui.table-actions>
                                <x-ui.table-action-item icon="visibility" wire:click="viewDetail({{ $transfer->id }})" aria-label="View Details">
                                    View Details
                                </x-ui.table-action-item>
                                @if ($transfer->status === \App\Enums\StockTransferStatusEnum::Draft && $transfer->from_store_id === $currentStoreId)
                                    <x-ui.table-action-item icon="local_shipping" wire:click="shipTransfer({{ $transfer->id }})" wire:confirm="Dispatch this stock transfer now? Stock at origin branch will be deducted." aria-label="Dispatch Goods">
                                        Dispatch Goods
                                    </x-ui.table-action-item>
                                @endif
                                @if ($transfer->status === \App\Enums\StockTransferStatusEnum::InTransit && $transfer->to_store_id === $currentStoreId)
                                    <x-ui.table-action-item icon="inventory" wire:click="openReceiveModal({{ $transfer->id }})" aria-label="Receive Goods">
                                        Receive Goods
                                    </x-ui.table-action-item>
                                @endif
                                @if ($transfer->status->canCancel())
                                    <x-ui.table-action-item icon="cancel" variant="destructive" wire:click="cancelTransfer({{ $transfer->id }})" wire:confirm="Cancel this stock transfer? If already in transit, stock will be returned to origin branch." aria-label="Cancel Transfer">
                                        Cancel Transfer
                                    </x-ui.table-action-item>
                                @endif
                            </x-ui.table-actions>
                        </td>
                    </tr>
                @endforeach
                @if ($transfers->hasPages())
                    <x-slot:pagination>
                        {{ $transfers->links() }}
                    </x-slot:pagination>
                @endif
            </x-erp.data-table>

    {{-- MODAL 1: Create Transfer Request Form --}}
    <x-ui.modal wire:model="showFormModal" maxWidth="3xl">
        <x-slot:header>
            <h5 class="text-lg font-bold text-foreground">New Stock Transfer Request</h5>
        </x-slot:header>
        <x-slot:content>
            @if ($errors->has('general'))
                <x-ui.alert variant="destructive" class="mb-4">
                    {{ $errors->first('general') }}
                </x-ui.alert>
            @endif

            <div class="space-y-4">
                <div>
                    <label class="block text-xs font-bold text-foreground uppercase tracking-wider mb-1">Destination Branch</label>
                    <select wire:model="to_store_id" class="form-input w-full p-3 rounded-xl border border-border text-sm">
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
                        <label class="block text-xs font-bold text-foreground uppercase tracking-wider">Transfer Items</label>
                        <button type="button" wire:click="addItemRow" class="text-xs font-semibold text-foreground hover:underline flex items-center gap-1">
                            <span class="material-symbols-rounded text-sm">add</span> Add Line
                        </button>
                    </div>

                    <div class="space-y-2 max-h-64 overflow-y-auto">
                        @foreach ($items as $index => $item)
                            <div class="p-3 rounded-2xl bg-secondary border border-border flex flex-col sm:flex-row items-center gap-3 text-sm">
                                <div class="w-full sm:w-32">
                                    <select wire:model.live="items.{{ $index }}.item_type" class="form-input w-full p-2 rounded-lg border border-border text-xs">
                                        <option value="product">Product</option>
                                        <option value="ingredient">Raw Material</option>
                                    </select>
                                </div>

                                <div class="w-full sm:flex-1">
                                    @if (($item['item_type'] ?? 'product') === 'product')
                                        <select wire:model="items.{{ $index }}.product_id" class="form-input w-full p-2 rounded-lg border border-border text-xs">
                                            <option value="">-- Select Product --</option>
                                            @foreach ($availableProducts as $p)
                                                <option value="{{ $p->id }}">{{ $p->name }} ({{ $p->code ?? '-' }})</option>
                                            @endforeach
                                        </select>
                                    @else
                                        <select wire:model="items.{{ $index }}.ingredient_id" class="form-input w-full p-2 rounded-lg border border-border text-xs">
                                            <option value="">-- Select Raw Material --</option>
                                            @foreach ($availableIngredients as $ing)
                                                <option value="{{ $ing->id }}">{{ $ing->name }} ({{ $ing->code ?? '-' }})</option>
                                            @endforeach
                                        </select>
                                    @endif
                                </div>

                                <div class="w-full sm:w-28">
                                    <input type="number" step="0.0001" min="0.0001" wire:model="items.{{ $index }}.quantity_requested" placeholder="Qty" class="form-input w-full p-2 rounded-lg border border-border text-xs text-right font-semibold">
                                </div>

                                <button type="button" wire:click="removeItemRow({{ $index }})" class="p-1 text-rose-500 hover:text-rose-700">
                                    <span class="material-symbols-rounded text-[20px]">delete</span>
                                </button>
                            </div>
                        @endforeach
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-bold text-foreground uppercase tracking-wider mb-1">Notes</label>
                    <textarea wire:model="notes" rows="2" placeholder="Delivery notes, transport details, waybill reference, etc." class="form-input w-full p-3 rounded-xl border border-border text-sm"></textarea>
                </div>
            </div>

            <div class="flex items-center justify-end gap-3 mt-6 pt-4 border-t border-border">
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
                    <h5 class="text-lg font-bold text-foreground">Receive Stock Transfer: {{ $activeTransfer->transfer_number }}</h5>
                    <p class="text-xs text-muted-foreground">Origin: {{ $activeTransfer->fromStore->name }} &rarr; Destination: {{ $activeTransfer->toStore->name }}</p>
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
                        <thead class="bg-secondary text-xs font-semibold text-muted-foreground uppercase">
                            <tr>
                                <th class="p-3">Item</th>
                                <th class="p-3 text-right">Shipped</th>
                                <th class="p-3 text-right w-36">Received</th>
                                <th class="p-3 w-48">Discrepancy Resolution</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            @foreach ($activeTransfer->items as $item)
                                <tr>
                                    <td class="p-3">
                                        <div class="font-bold text-foreground">
                                            {{ $item->item_type === 'product' ? ($item->product?->product_name ?? $item->product?->name) : $item->ingredient?->name }}
                                        </div>
                                        <div class="text-xs text-muted-foreground uppercase">{{ $item->item_type }}</div>
                                    </td>
                                    <td class="p-3 text-right font-semibold tabular-nums text-foreground">
                                        {{ number_format((float) $item->quantity_shipped, 2) }}
                                    </td>
                                    <td class="p-3 text-right">
                                        <input type="number" step="0.0001" min="0" max="{{ $item->quantity_shipped }}" wire:model.live="receivedQuantities.{{ $item->id }}" class="form-input w-full p-2 text-right tabular-nums rounded-lg border border-border text-sm font-semibold">
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
                    <label class="block text-xs font-bold text-foreground uppercase tracking-wider mb-1">Receiving Notes</label>
                    <input type="text" wire:model="receiveNotes" placeholder="Condition of goods upon arrival..." class="form-input w-full p-2 rounded-xl border border-border text-xs">
                </div>

                <div class="flex items-center justify-end gap-3 mt-6 pt-4 border-t border-border">
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
                    <h5 class="text-lg font-bold text-foreground">Transfer Details: {{ $selectedTransfer->transfer_number }}</h5>
                    <p class="text-xs text-muted-foreground">Status: {{ $selectedTransfer->status->label() }}</p>
                </div>
            @endif
        </x-slot:header>
        <x-slot:content>
            @if ($selectedTransfer)
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-6 p-4 rounded-2xl bg-secondary text-xs">
                    <div>
                        <span class="text-muted-foreground block">Origin Branch</span>
                        <span class="font-semibold text-foreground">{{ $selectedTransfer->fromStore->name }}</span>
                    </div>
                    <div>
                        <span class="text-muted-foreground block">Destination Branch</span>
                        <span class="font-semibold text-foreground">{{ $selectedTransfer->toStore->name }}</span>
                    </div>
                    <div>
                        <span class="text-muted-foreground block">Shipped At</span>
                        <span class="font-semibold text-foreground">{{ $selectedTransfer->shipped_at?->format('d M Y H:i') ?? '-' }}</span>
                    </div>
                    <div>
                        <span class="text-muted-foreground block">Received At</span>
                        <span class="font-semibold text-foreground">{{ $selectedTransfer->received_at?->format('d M Y H:i') ?? '-' }}</span>
                    </div>
                </div>

                <div class="max-h-80 overflow-y-auto mb-4">
                    <table class="table-standard w-full min-w-full text-left text-sm">
                        <thead class="bg-secondary text-xs font-semibold text-muted-foreground uppercase">
                            <tr>
                                <th class="p-3">Item</th>
                                <th class="p-3 text-right">Requested</th>
                                <th class="p-3 text-right">Shipped</th>
                                <th class="p-3 text-right">Received</th>
                                <th class="p-3 text-right">Carrying Cost</th>
                                <th class="p-3 text-center">Discrepancy Resolution</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            @foreach ($selectedTransfer->items as $item)
                                <tr>
                                    <td class="p-3">
                                        <div class="font-bold text-foreground">
                                            {{ $item->item_type === 'product' ? ($item->product?->product_name ?? $item->product?->name) : $item->ingredient?->name }}
                                        </div>
                                        <div class="text-xs text-muted-foreground uppercase">{{ $item->item_type }}</div>
                                    </td>
                                    <td class="p-3 text-right font-medium tabular-nums text-foreground">{{ number_format((float) $item->quantity_requested, 2) }}</td>
                                    <td class="p-3 text-right font-medium tabular-nums text-foreground">{{ number_format((float) $item->quantity_shipped, 2) }}</td>
                                    <td class="p-3 text-right font-semibold tabular-nums text-foreground">{{ $item->quantity_received !== null ? number_format((float) $item->quantity_received, 2) : '-' }}</td>
                                    <td class="p-3 text-right text-xs font-sans tabular-nums">Rp {{ number_format((float) $item->carrying_unit_cost, 0, ',', '.') }}</td>
                                    <td class="p-3 text-center text-xs">
                                        <span class="text-xs text-muted-foreground font-medium">
                                            {{ $item->discrepancy_resolution->value }}
                                        </span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="flex items-center justify-end gap-3 pt-4 border-t border-border">
                    <x-ui.button type="button" variant="secondary" wire:click="closeDetailModal">Close</x-ui.button>
                </div>
            @endif
        </x-slot:content>
    </x-ui.modal>
</div>
