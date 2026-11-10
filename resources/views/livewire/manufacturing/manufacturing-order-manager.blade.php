<div class="py-6 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
    <x-erp.module-sub-nav module="inventory" />

    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-foreground tracking-tight">
                Discrete Manufacturing Orders (MO)
            </h1>
            <p class="text-sm text-muted-foreground mt-1">
                Plan, start, and complete production runs with automated recipe BOM material consumption.
            </p>
        </div>
        <div class="flex items-center gap-2">
            <button wire:click="$set('isCreateModalOpen', true)"
                class="inline-flex items-center gap-2 px-4 py-2 bg-primary hover:bg-primary/90 text-primary-foreground text-sm font-semibold rounded-xl shadow-sm transition-colors">
                <span class="material-symbols-rounded text-[20px]">add</span>
                Create Work Order
            </button>
        </div>
    </div>

    @if (session()->has('message'))
        <div class="p-4 bg-emerald-50 border border-emerald-200 rounded-xl flex items-center gap-3 text-emerald-800 text-sm font-medium">
            <span class="material-symbols-rounded text-emerald-600 text-[20px]">check_circle</span>
            {{ session('message') }}
        </div>
    @endif

    @if (session()->has('error'))
        <div class="p-4 bg-red-50 border border-red-200 rounded-xl flex items-center gap-3 text-red-800 text-sm font-medium">
            <span class="material-symbols-rounded text-red-600 text-[20px]">error</span>
            {{ session('error') }}
        </div>
    @endif

    <!-- Search & Filter Bar -->
    <x-erp.filter-bar
        search-placeholder="Search MO number or product..."
        search-model="search"
        :reset-method="null"
    >
        <x-slot:filters>
            <select wire:model.live="statusFilter"
                class="bg-card border border-border text-foreground text-xs rounded-xl px-3 py-2 font-semibold focus:outline-none focus:ring-1 focus:ring-ring">
                <option value="ALL">All Status</option>
                <option value="PLANNED">Planned</option>
                <option value="IN_PROGRESS">In Progress</option>
                <option value="COMPLETED">Completed</option>
            </select>
        </x-slot:filters>
    </x-erp.filter-bar>

    <!-- Orders Table -->
    <div class="card-surface rounded-2xl border border-border shadow-xs overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm text-foreground">
                <thead class="bg-muted/50 text-muted-foreground font-semibold text-2xs uppercase tracking-wider border-b border-border">
                    <tr>
                        <th class="px-6 py-3.5">Order Number</th>
                        <th class="px-6 py-3.5">Target Product</th>
                        <th class="px-6 py-3.5">Planned Qty</th>
                        <th class="px-6 py-3.5">Produced Qty</th>
                        <th class="px-6 py-3.5">Status</th>
                        <th class="px-6 py-3.5 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($orders as $mo)
                        <tr class="hover:bg-muted/30 transition-colors">
                            <td class="px-6 py-4 font-mono font-medium text-xs text-foreground">
                                {{ $mo->order_number }}
                            </td>
                            <td class="px-6 py-4 font-medium text-foreground">
                                {{ $mo->product?->product_name ?? $mo->product?->name ?? 'N/A' }}
                            </td>
                            <td class="px-6 py-4 font-semibold text-foreground">
                                {{ number_format((float) $mo->planned_quantity, 2) }}
                            </td>
                            <td class="px-6 py-4 font-semibold text-foreground">
                                {{ number_format((float) $mo->produced_quantity, 2) }}
                            </td>
                            <td class="px-6 py-4">
                                @php
                                    $moDot = match($mo->status) {
                                        'COMPLETED' => 'bg-success',
                                        'IN_PROGRESS' => 'bg-info',
                                        'PLANNED' => 'bg-warning',
                                        'CANCELLED' => 'bg-destructive',
                                        default => 'bg-neutral',
                                    };
                                    $moLabel = match($mo->status) {
                                        'COMPLETED' => 'Completed',
                                        'IN_PROGRESS' => 'In Progress',
                                        'PLANNED' => 'Planned',
                                        default => $mo->status,
                                    };
                                @endphp
                                <span class="inline-flex items-center gap-1.5 text-xs text-foreground font-medium">
                                    <span class="w-1.5 h-1.5 rounded-full {{ $moDot }}"></span>
                                    {{ $moLabel }}
                                </span>
                            </td>
                            <td class="px-6 py-4 text-right whitespace-nowrap">
                                @if ($mo->status === 'PLANNED')
                                    <x-ui.table-actions>
                                        <x-ui.table-action-item icon="play_arrow" wire:click="startOrder({{ $mo->id }})" aria-label="Start Manufacturing Order">
                                            Start Order
                                        </x-ui.table-action-item>
                                    </x-ui.table-actions>
                                @elseif ($mo->status === 'IN_PROGRESS')
                                    <x-ui.table-actions>
                                        <x-ui.table-action-item icon="check" wire:click="completeOrder({{ $mo->id }})" aria-label="Complete & Deduct BOM">
                                            Complete & Deduct BOM
                                        </x-ui.table-action-item>
                                    </x-ui.table-actions>
                                @else
                                    <span class="text-xs text-muted-foreground">-</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-6 py-12 text-center text-muted-foreground">
                                <span class="material-symbols-rounded text-4xl block mb-2 text-muted-foreground/50">precision_manufacturing</span>
                                No manufacturing orders recorded. Click "Create Work Order" to begin production.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($orders->hasPages())
            <div class="p-4 border-t border-border">
                {{ $orders->links() }}
            </div>
        @endif
    </div>

    <!-- Create MO Modal -->
    @if ($isCreateModalOpen)
        <x-ui.modal wire:model="isCreateModalOpen" title="Create Manufacturing Order" maxWidth="md" onClose="$wire.set('isCreateModalOpen', false)">
            <div class="space-y-4">
                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">Target Product</label>
                    <select wire:model="selectedProductId" class="w-full bg-card border border-border rounded-xl text-sm p-2 text-foreground focus:ring-1 focus:ring-ring">
                        <option value="">-- Choose Product --</option>
                        @foreach ($products as $prod)
                            <option value="{{ $prod->id }}">{{ $prod->product_name ?? $prod->name }}</option>
                        @endforeach
                    </select>
                    @error('selectedProductId') <span class="text-2xs text-destructive mt-1 block">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">Planned Production Quantity</label>
                    <input type="number" step="0.0001" wire:model="plannedQuantity" min="0.0001" class="w-full bg-card border border-border rounded-xl text-sm p-2 text-foreground focus:ring-1 focus:ring-ring">
                    @error('plannedQuantity') <span class="text-2xs text-destructive mt-1 block">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">Planned Start Date</label>
                    <input type="date" wire:model="plannedStartDate" class="w-full bg-card border border-border rounded-xl text-sm p-2 text-foreground focus:ring-1 focus:ring-ring">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">Notes / Instructions</label>
                    <textarea wire:model="notes" rows="2" class="w-full bg-card border border-border rounded-xl text-sm p-2 text-foreground focus:ring-1 focus:ring-ring" placeholder="Optional notes..."></textarea>
                </div>
            </div>

            <x-slot:footer>
                <div class="flex justify-end gap-2 w-full">
                    <button type="button" wire:click="$set('isCreateModalOpen', false)" class="px-4 py-2 text-muted-foreground hover:text-foreground rounded-xl text-sm font-medium transition-colors">
                        Cancel
                    </button>
                    <button type="button" wire:click="createOrder" class="px-4 py-2 bg-primary text-primary-foreground rounded-xl text-sm font-semibold hover:bg-primary/90 transition-colors shadow-xs">
                        Create Order
                    </button>
                </div>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
