<div class="py-6 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
    <x-erp.module-sub-nav module="inventory" />

    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-foreground tracking-tight flex items-center gap-2">
                <span class="material-symbols-rounded text-primary text-[28px]">precision_manufacturing</span>
                Discrete Manufacturing Orders (MO)
            </h1>
            <p class="text-sm text-muted-foreground mt-1">
                Plan, start, and complete production runs with automated recipe BOM material consumption.
            </p>
        </div>
        <div class="flex items-center gap-2">
            <button wire:click="$set('isCreateModalOpen', true)"
                class="inline-flex items-center gap-2 px-4 py-2 bg-primary hover:bg-primary/90 text-primary-foreground text-sm font-semibold rounded-xl shadow-sm transition-colors">
                <span class="material-symbols-rounded text-[18px]">add</span>
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
    <div class="bg-card p-4 rounded-2xl border border-border shadow-sm flex flex-col sm:flex-row gap-3 items-center justify-between">
        <div class="w-full sm:w-80 relative">
            <span class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-muted-foreground">
                <span class="material-symbols-rounded text-[18px]">search</span>
            </span>
            <input type="text" wire:model.live.debounce.300ms="search" placeholder="Search MO number or product..."
                class="w-full pl-10 pr-4 py-2 bg-background border border-border rounded-xl text-sm text-foreground focus:ring-2 focus:ring-primary">
        </div>
        <div class="flex items-center gap-2 w-full sm:w-auto">
            <select wire:model.live="statusFilter"
                class="bg-background border border-border text-foreground text-sm rounded-xl px-3 py-2 focus:ring-2 focus:ring-primary">
                <option value="ALL">All Status</option>
                <option value="PLANNED">Planned</option>
                <option value="IN_PROGRESS">In Progress</option>
                <option value="COMPLETED">Completed</option>
            </select>
        </div>
    </div>

    <!-- Orders Table -->
    <div class="bg-card rounded-2xl border border-border shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm text-foreground">
                <thead class="bg-muted text-muted-foreground font-semibold text-xs uppercase tracking-wider border-b border-border">
                    <tr>
                        <th class="px-6 py-3">Order Number</th>
                        <th class="px-6 py-3">Target Product</th>
                        <th class="px-6 py-3">Planned Qty</th>
                        <th class="px-6 py-3">Produced Qty</th>
                        <th class="px-6 py-3">Status</th>
                        <th class="px-6 py-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($orders as $mo)
                        <tr class="hover:bg-muted/50 transition-colors">
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
                                @if ($mo->status === 'COMPLETED')
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-emerald-100 text-emerald-800">
                                        Completed
                                    </span>
                                @elseif ($mo->status === 'IN_PROGRESS')
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-blue-100 text-blue-800">
                                        In Progress
                                    </span>
                                @else
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-amber-100 text-amber-800">
                                        {{ $mo->status }}
                                    </span>
                                @endif
                            </td>
                            <td class="px-6 py-4 text-right space-x-2">
                                @if ($mo->status === 'PLANNED')
                                    <button wire:click="startOrder({{ $mo->id }})"
                                        class="inline-flex items-center gap-1 text-xs font-semibold text-primary hover:text-primary/80">
                                        <span class="material-symbols-rounded text-[16px]">play_arrow</span>
                                        Start
                                    </button>
                                @elseif ($mo->status === 'IN_PROGRESS')
                                    <button wire:click="completeOrder({{ $mo->id }})"
                                        class="inline-flex items-center gap-1 text-xs font-semibold text-emerald-600 hover:text-emerald-700">
                                        <span class="material-symbols-rounded text-[16px]">check</span>
                                        Complete & Deduct BOM
                                    </button>
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
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm p-4">
            <div class="bg-card border border-border rounded-2xl max-w-md w-full p-6 space-y-4 shadow-xl">
                <div class="flex items-center justify-between">
                    <h3 class="text-lg font-bold text-foreground">Create Manufacturing Order</h3>
                    <button wire:click="$set('isCreateModalOpen', false)" class="text-muted-foreground hover:text-foreground">
                        <span class="material-symbols-rounded">close</span>
                    </button>
                </div>
                <div class="space-y-4">
                    <div>
                        <label class="block text-xs font-semibold text-foreground mb-1">Target Product</label>
                        <select wire:model="selectedProductId" class="w-full bg-background border border-border rounded-xl text-sm p-2 text-foreground focus:ring-2 focus:ring-primary">
                            <option value="">-- Choose Product --</option>
                            @foreach ($products as $prod)
                                <option value="{{ $prod->id }}">{{ $prod->product_name ?? $prod->name }}</option>
                            @endforeach
                        </select>
                        @error('selectedProductId') <span class="text-xs text-red-500">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-foreground mb-1">Planned Production Quantity</label>
                        <input type="number" step="0.0001" wire:model="plannedQuantity" min="0.0001" class="w-full bg-background border border-border rounded-xl text-sm p-2 text-foreground focus:ring-2 focus:ring-primary">
                        @error('plannedQuantity') <span class="text-xs text-red-500">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-foreground mb-1">Planned Start Date</label>
                        <input type="date" wire:model="plannedStartDate" class="w-full bg-background border border-border rounded-xl text-sm p-2 text-foreground focus:ring-2 focus:ring-primary">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-foreground mb-1">Notes / Instructions</label>
                        <textarea wire:model="notes" rows="2" class="w-full bg-background border border-border rounded-xl text-sm p-2 text-foreground focus:ring-2 focus:ring-primary" placeholder="Optional notes..."></textarea>
                    </div>
                </div>
                <div class="flex justify-end gap-2 pt-2">
                    <button wire:click="$set('isCreateModalOpen', false)" class="px-4 py-2 border border-border text-foreground rounded-xl text-sm font-medium hover:bg-muted">
                        Cancel
                    </button>
                    <button wire:click="createOrder" class="px-4 py-2 bg-primary text-primary-foreground rounded-xl text-sm font-semibold hover:bg-primary/90">
                        Create Order
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
