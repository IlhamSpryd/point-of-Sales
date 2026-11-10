<div data-gsap="page-enter">
    @include('inventory.partials.sub-nav')
    
    <x-erp.page-header
        title="Stock Adjustments"
        description="Direct structured stock corrections: damage, expiration, shrinkage, and production waste."
    >
        <x-slot:actions>
            <x-ui.button type="button" variant="primary" wire:click="openCreateModal" x-on:click="window.erpMotion?.pulseHighlight($el)">
                <span class="material-symbols-rounded text-[20px]">add</span> New Stock Adjustment
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
        search-placeholder="Search adjustment number, notes..."
        search-model="search"
        :reset-method="null"
    >
        <x-slot:filters>
            <select wire:model.live="statusFilter"
                    class="h-9 px-3 rounded-medium border border-input bg-background text-foreground text-xs font-semibold focus-visible:ring-1 focus-visible:ring-ring outline-none">
                <option value="ALL">All Statuses</option>
                <option value="DRAFT">Draft</option>
                <option value="POSTED">Posted</option>
                <option value="CANCELLED">Cancelled</option>
            </select>

            <select wire:model.live="reasonFilter"
                    class="h-9 px-3 rounded-medium border border-input bg-background text-foreground text-xs font-semibold focus-visible:ring-1 focus-visible:ring-ring outline-none">
                <option value="ALL">All Reasons</option>
                @foreach ($reasons as $r)
                    <option value="{{ $r->value }}">{{ $r->label() }}</option>
                @endforeach
            </select>
        </x-slot:filters>
    </x-erp.filter-bar>

    {{-- Stock Adjustments Table --}}
    <x-erp.data-table
        loading-target="search, statusFilter"
        :is-empty="$adjustments->isEmpty()"
        empty-title="No stock adjustment documents recorded."
        empty-description="Create an adjustment to rectify stock discrepancies or record shrinkage."
        empty-icon="tune"
        :colspan="7"
    >
                <x-slot:head>
                    <th scope="col" class="px-6 py-4 text-xs font-semibold text-muted-foreground uppercase tracking-wider">Adjustment #</th>
                    <th scope="col" class="px-6 py-4 text-xs font-semibold text-muted-foreground uppercase tracking-wider">Adjustment Date</th>
                    <th scope="col" class="px-6 py-4 text-xs font-semibold text-muted-foreground uppercase tracking-wider">Reason Code</th>
                    <th scope="col" class="px-6 py-4 text-xs font-semibold text-muted-foreground uppercase tracking-wider text-center">Status</th>
                    <th scope="col" class="px-6 py-4 text-xs font-semibold text-muted-foreground uppercase tracking-wider text-right font-sans tabular-nums numeric">Items</th>
                    <th scope="col" class="px-6 py-4 text-xs font-semibold text-muted-foreground uppercase tracking-wider text-right font-sans tabular-nums numeric">Total Value</th>
                    <th scope="col" class="px-6 py-4 text-xs font-semibold text-muted-foreground uppercase tracking-wider text-right">Actions</th>
                </x-slot:head>

                @foreach ($adjustments as $adjustment)
                    <tr class="hover:bg-secondary transition-colors duration-150">
                        <td class="px-6 py-4 whitespace-nowrap">
                            <span class="text-sm font-bold text-foreground">{{ $adjustment->adjustment_number }}</span>
                            <div class="text-xs text-muted-foreground">Created by: {{ $adjustment->creator->name ?? 'System' }}</div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-foreground">
                            {{ $adjustment->adjusted_at->format('d M Y H:i') }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-foreground">
                            <span class="font-semibold">{{ $adjustment->reason_code->label() }}</span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            @php
                                $adjDot = match($adjustment->status->badgeType()) {
                                    'success' => 'bg-success',
                                    'warning' => 'bg-warning',
                                    'danger' => 'bg-destructive',
                                    default => 'bg-neutral',
                                };
                            @endphp
                            <span class="inline-flex items-center gap-1.5 text-xs text-foreground font-medium">
                                <span class="w-1.5 h-1.5 rounded-full {{ $adjDot }}"></span>
                                {{ $adjustment->status->label() }}
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-semibold text-foreground">
                            {{ $adjustment->items->count() }} items
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-bold text-foreground">
                            Rp {{ number_format((float) $adjustment->calculateTotalValue(), 2, ',', '.') }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right">
                            <x-ui.table-actions>
                                <x-ui.table-action-item icon="visibility" wire:click="viewDetails({{ $adjustment->id }})" aria-label="View Details">
                                    View Details
                                </x-ui.table-action-item>
                                @if($adjustment->isDraft())
                                    <x-ui.table-action-item icon="task_alt" wire:click="post({{ $adjustment->id }})" wire:confirm="Post this stock adjustment to branch balance?" aria-label="Post to Stock">
                                        Post Adjustment
                                    </x-ui.table-action-item>
                                    <x-ui.table-action-item icon="cancel" variant="destructive" wire:click="cancel({{ $adjustment->id }})" wire:confirm="Cancel this draft stock adjustment?" aria-label="Cancel Adjustment">
                                        Cancel Adjustment
                                    </x-ui.table-action-item>
                                @endif
                            </x-ui.table-actions>
                        </td>
                    </tr>
                @endforeach
                @if ($adjustments->hasPages())
                    <x-slot:pagination>
                        {{ $adjustments->links() }}
                    </x-slot:pagination>
                @endif
            </x-erp.data-table>

    {{-- Create / Edit Draft Modal --}}
    <x-ui.modal wire:model="showFormModal" maxWidth="4xl">
        <x-slot:header>
            <h3 class="text-lg font-bold text-foreground">
                {{ $isEditing ? 'Edit Stock Adjustment Draft' : 'New Stock Adjustment' }}
            </h3>
        </x-slot:header>
        <x-slot:content>
            <div class="py-2 space-y-4">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-semibold text-muted-foreground mb-1">Reason Code *</label>
                        <select wire:model.live="reason_code"
                                class="form-input w-full px-3 py-2 rounded-xl border border-border text-sm">
                            @foreach ($reasons as $r)
                                <option value="{{ $r->value }}">{{ $r->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-muted-foreground mb-1">Adjustment Date & Time *</label>
                        <input type="datetime-local" wire:model="adjusted_at"
                                class="form-input w-full px-3 py-2 rounded-xl border border-border text-sm">
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-muted-foreground mb-1">Notes</label>
                    <textarea wire:model="notes" rows="2" placeholder="Adjustment notes or remarks..."
                              class="form-input w-full px-3 py-2 rounded-xl border border-border text-sm"></textarea>
                </div>

                {{-- Item Lines --}}
                <div class="pt-2">
                    <div class="flex items-center justify-between mb-2">
                        <h5 class="text-sm font-bold text-foreground">Adjustment Line Items</h5>
                        <button type="button" wire:click="addItemLine" class="text-xs font-semibold text-emerald-600 hover:text-emerald-800 flex items-center gap-1">
                            <span class="material-symbols-rounded text-[16px]">add_circle</span> Add Line
                        </button>
                    </div>

                    <div class="space-y-3">
                        @foreach ($items as $index => $item)
                            <div class="p-3 rounded-2xl border border-border bg-slate-50/50 space-y-2">
                                <div class="grid grid-cols-12 gap-2 items-center">
                                    <div class="col-span-12 sm:col-span-2">
                                        <select wire:model.live="items.{{ $index }}.item_type" class="w-full text-xs py-1.5 px-2 rounded-lg border border-border bg-white">
                                            <option value="product">Product</option>
                                            <option value="ingredient">Raw Material</option>
                                        </select>
                                    </div>

                                    <div class="col-span-12 sm:col-span-4">
                                        @if ($item['item_type'] === 'product')
                                            <select wire:model="items.{{ $index }}.product_id" class="w-full text-xs py-1.5 px-2 rounded-lg border border-border bg-white">
                                                <option value="">Select Product...</option>
                                                @foreach ($products as $prod)
                                                    <option value="{{ $prod->id }}">{{ $prod->product_name }} ({{ $prod->product_code ?: 'No Code' }})</option>
                                                @endforeach
                                            </select>
                                        @else
                                            <select wire:model="items.{{ $index }}.ingredient_id" class="w-full text-xs py-1.5 px-2 rounded-lg border border-border bg-white">
                                                <option value="">Select Raw Material...</option>
                                                @foreach ($ingredients as $ing)
                                                    <option value="{{ $ing->id }}">{{ $ing->name }} ({{ $ing->unit }})</option>
                                                @endforeach
                                            </select>
                                        @endif
                                    </div>

                                    <div class="col-span-6 sm:col-span-2">
                                        <select wire:model="items.{{ $index }}.direction" class="w-full text-xs py-1.5 px-2 rounded-lg border border-border bg-white font-semibold">
                                            <option value="OUT">Outflow (OUT)</option>
                                            <option value="IN">Inflow (IN)</option>
                                        </select>
                                    </div>

                                    <div class="col-span-6 sm:col-span-3">
                                        <input type="number" step="0.0001" min="0.0001" wire:model="items.{{ $index }}.quantity" placeholder="Qty" title="Quantity"
                                               class="w-full text-xs py-1.5 px-2 rounded-lg border border-border bg-white text-right font-medium">
                                    </div>

                                    <div class="col-span-12 sm:col-span-1 text-right">
                                        <button type="button" wire:click="removeItemLine({{ $index }})" class="text-rose-500 hover:text-rose-700">
                                            <span class="material-symbols-rounded text-[20px]">delete</span>
                                        </button>
                                    </div>
                                </div>

                                <div class="grid grid-cols-12 gap-2 text-xs">
                                    <div class="col-span-12 sm:col-span-6">
                                        <input type="text" wire:model="items.{{ $index }}.reason_note" placeholder="Line note (optional)"
                                               class="w-full text-xs py-1 px-2 rounded-lg border border-border bg-white placeholder-slate-400">
                                    </div>
                                    <div class="col-span-12 sm:col-span-6">
                                        <input type="number" step="0.0001" min="0" wire:model="items.{{ $index }}.unit_cost_snapshot" placeholder="Unit cost snapshot (0 = auto WAC)"
                                               class="w-full text-xs py-1 px-2 rounded-lg border border-border bg-white text-right text-slate-700">
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>

            <div class="pt-4 border-t border-border flex items-center justify-end gap-3">
                <x-ui.button type="button" variant="secondary" wire:click="$set('showFormModal', false)">
                    Cancel
                </x-ui.button>
                <x-ui.button type="button" variant="primary" wire:click="saveDraft" x-on:click="window.erpMotion?.pulseHighlight($el)">
                    Save Draft
                </x-ui.button>
            </div>
        </x-slot:content>
    </x-ui.modal>

    {{-- Adjustment Details Modal --}}
    <x-ui.modal wire:model="showDetailModal" maxWidth="4xl">
        <x-slot:header>
            @if ($selectedAdjustment)
                <div>
                    <h3 class="text-lg font-bold text-foreground">Adjustment Details: {{ $selectedAdjustment->adjustment_number }}</h3>
                    <p class="text-xs text-muted-foreground">Branch: {{ $selectedAdjustment->store->name ?? '-' }} | Created: {{ $selectedAdjustment->created_at->format('d M Y H:i') }}</p>
                </div>
            @endif
        </x-slot:header>
        <x-slot:content>
            @if ($selectedAdjustment)
                <div class="py-2 space-y-4">
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 p-4 rounded-2xl bg-slate-50 text-xs">
                        <div>
                            <span class="text-muted-foreground block mb-0.5">Status:</span>
                            <span class="inline-flex items-center gap-1.5 text-xs text-foreground font-medium">
                                <span class="w-1.5 h-1.5 rounded-full {{ match($selectedAdjustment->status->value) { 'approved' => 'bg-success', 'pending' => 'bg-warning', 'rejected' => 'bg-destructive', default => 'bg-neutral' } }}"></span>
                                {{ $selectedAdjustment->status->label() }}
                            </span>
                        </div>
                        <div>
                            <span class="text-muted-foreground block">Reason Code:</span>
                            <span class="font-semibold text-foreground">{{ $selectedAdjustment->reason_code->label() }}</span>
                        </div>
                        <div>
                            <span class="text-muted-foreground block">Adjustment Date:</span>
                            <span class="font-semibold text-foreground">{{ $selectedAdjustment->adjusted_at->format('d M Y H:i') }}</span>
                        </div>
                        <div>
                            <span class="text-muted-foreground block">Posted By:</span>
                            <span class="font-semibold text-foreground">{{ $selectedAdjustment->poster->name ?? '-' }}</span>
                        </div>
                    </div>

                    @if($selectedAdjustment->notes)
                        <div class="text-xs text-foreground p-3 rounded-xl bg-slate-50 border border-border">
                            <span class="font-semibold block text-muted-foreground">Notes:</span>
                            {{ $selectedAdjustment->notes }}
                        </div>
                    @endif

                    <div class="overflow-x-auto border border-border rounded-2xl">
                        <table class="table-standard min-w-full divide-y divide-border text-xs">
                            <thead class="bg-slate-50 text-muted-foreground uppercase">
                                <tr>
                                    <th class="px-4 py-3 text-left">Type</th>
                                    <th class="px-4 py-3 text-left">Item Name</th>
                                    <th class="px-4 py-3 text-center">Direction</th>
                                    <th class="px-4 py-3 text-right">Quantity</th>
                                    <th class="px-4 py-3 text-right">Unit Cost</th>
                                    <th class="px-4 py-3 text-right">Subtotal</th>
                                    <th class="px-4 py-3 text-left">Notes</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border">
                                @foreach ($selectedAdjustment->items as $item)
                                    <tr>
                                        <td class="px-4 py-2 font-medium capitalize">{{ $item->item_type }}</td>
                                        <td class="px-4 py-2 font-semibold text-foreground">{{ $item->item_name }}</td>
                                        <td class="px-4 py-2 text-center font-bold {{ $item->direction === 'IN' ? 'text-emerald-600' : 'text-rose-600' }}">
                                            {{ $item->direction }}
                                        </td>
                                        <td class="px-4 py-2 text-right font-medium tabular-nums">{{ number_format((float) $item->quantity, 4) }}</td>
                                        <td class="px-4 py-2 text-right font-medium tabular-nums">Rp {{ number_format((float) $item->unit_cost_snapshot, 2, ',', '.') }}</td>
                                        <td class="px-4 py-2 text-right font-bold text-foreground tabular-nums">Rp {{ number_format((float) $item->subtotal, 2, ',', '.') }}</td>
                                        <td class="px-4 py-2 text-muted-foreground">{{ $item->reason_note ?: '-' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot class="bg-slate-50 font-bold">
                                <tr>
                                    <td colspan="5" class="px-4 py-3 text-right text-foreground uppercase">Total Adjustment Value:</td>
                                    <td class="px-4 py-3 text-right text-foreground tabular-nums">Rp {{ number_format((float) $selectedAdjustment->calculateTotalValue(), 2, ',', '.') }}</td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>

                <div class="pt-4 border-t border-border flex justify-end">
                    <x-ui.button type="button" variant="secondary" wire:click="$set('showDetailModal', false)">
                        Close
                    </x-ui.button>
                </div>
            @endif
        </x-slot:content>
    </x-ui.modal>
</div>
