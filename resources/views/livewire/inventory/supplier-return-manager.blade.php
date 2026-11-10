<div data-gsap="page-enter">
    @include('inventory.partials.sub-nav')
    {{-- Header & Primary Action --}}
    <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between mb-6 gap-4">
        <div>
            <h4 class="text-xl font-bold text-foreground tracking-tight">Supplier Returns</h4>
            <p class="text-sm font-medium text-muted-foreground mt-1">
                Manage product and raw material returns to vendors with automated stock deductions.
            </p>
        </div>
        <div class="flex flex-col sm:flex-row items-center gap-3 w-full sm:w-auto">
            <x-ui.button type="button" variant="primary" wire:click="openCreateModal" x-on:click="window.erpMotion?.pulseHighlight($el)">
                <span class="material-symbols-rounded text-[20px]">add</span> New Supplier Return
            </x-ui.button>
        </div>
    </div>

    {{-- Enterprise Vendor Return Notice --}}
    <div class="mb-5 p-4 rounded-2xl border border-border bg-muted/40 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
        <div>
            <h5 class="text-sm font-semibold text-foreground">Retur Pembelian Vendor & Nota Debet (Enterprise P2P)</h5>
            <p class="text-xs text-muted-foreground mt-0.5">
                Pengembalian barang ke vendor yang terhubung dengan Purchase Order dan penerbitan Nota Debet Hutang (AP Debit Note) dikelola terpusat di Modul Purchasing.
            </p>
        </div>
        <a href="{{ route('purchasing.returns.index') }}" wire:navigate class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold rounded-xl bg-background border border-border hover:bg-muted text-foreground transition-colors shrink-0 shadow-xs">
            <span>Buka Purchasing Returns</span>
            <span class="material-symbols-rounded text-sm">arrow_forward</span>
        </a>
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
    <x-erp.filter-bar
        search-placeholder="Search return number, supplier name..."
        search-model="search"
        :reset-method="null"
    >
        <x-slot:filters>
            <select wire:model.live="statusFilter"
                    class="h-9 px-3 rounded-medium border border-input bg-background text-foreground text-xs font-semibold focus-visible:ring-1 focus-visible:ring-ring outline-none">
                <option value="ALL">All Statuses</option>
                <option value="DRAFT">Draft</option>
                <option value="POSTED">Posted</option>
                <option value="REVERSED">Reversed</option>
                <option value="CANCELLED">Cancelled</option>
            </select>
        </x-slot:filters>
    </x-erp.filter-bar>

    {{-- Supplier Returns Table --}}
    <x-erp.data-table
        loading-target="search, statusFilter"
        :is-empty="$returns->isEmpty()"
        empty-title="No supplier return documents found."
        empty-description="Create a return to supplier against delivered goods."
        empty-icon="keyboard_return"
        :colspan="7"
    >
        <x-slot:head>
            <th class="px-6 py-4 text-xs font-semibold text-muted-foreground uppercase tracking-wider">Return #</th>
            <th class="px-6 py-4 text-xs font-semibold text-muted-foreground uppercase tracking-wider">Return Date</th>
            <th class="px-6 py-4 text-xs font-semibold text-muted-foreground uppercase tracking-wider">Supplier</th>
            <th class="px-6 py-4 text-xs font-semibold text-muted-foreground uppercase tracking-wider">Reference</th>
            <th class="px-6 py-4 text-xs font-semibold text-muted-foreground uppercase tracking-wider">Status</th>
            <th class="px-6 py-4 text-xs font-semibold text-muted-foreground uppercase tracking-wider">Created / Posted By</th>
            <th class="px-6 py-4 text-xs font-semibold text-muted-foreground uppercase tracking-wider text-right">Actions</th>
        </x-slot:head>

        @foreach ($returns as $ret)
                        <tr class="hover:bg-secondary/40 transition-colors">
                            <td class="px-6 py-4 font-bold text-foreground">
                                {{ $ret->return_number }}
                            </td>
                            <td class="px-6 py-4 text-muted-foreground text-xs">
                                {{ $ret->returned_at->format('d M Y H:i') }}
                            </td>
                            <td class="px-6 py-4 font-medium text-foreground">
                                {{ $ret->supplier_name ?? '-' }}
                            </td>
                            <td class="px-6 py-4 text-xs text-muted-foreground">
                                @if($ret->goodsReceipt)
                                    <span class="inline-flex items-center gap-1 font-sans text-xs text-foreground font-medium">
                                        <span class="material-symbols-rounded text-[14px]">receipt_long</span>
                                        {{ $ret->goodsReceipt->receipt_number }}
                                    </span>
                                @elseif($ret->supplier_reference)
                                    {{ $ret->supplier_reference }}
                                @else
                                    -
                                @endif
                            </td>
                            <td class="px-6 py-4">
                                @php
                                    $retDot = match($ret->status->badgeType()) {
                                        'success' => 'bg-success',
                                        'warning' => 'bg-warning',
                                        'danger' => 'bg-destructive',
                                        default => 'bg-neutral',
                                    };
                                @endphp
                                <span class="inline-flex items-center gap-1.5 text-xs text-foreground font-medium">
                                    <span class="w-1.5 h-1.5 rounded-full {{ $retDot }}"></span>
                                    {{ $ret->status->label() }}
                                </span>
                            </td>
                            <td class="px-6 py-4 text-xs text-muted-foreground">
                                <div>Created: {{ $ret->creator?->name ?? 'System' }}</div>
                                @if($ret->poster)
                                    <div class="text-[11px] text-emerald-600">Posted: {{ $ret->poster->name }}</div>
                                @endif
                            </td>
                            <td class="px-6 py-4 text-right whitespace-nowrap">
                                <x-ui.table-actions>
                                    <x-ui.table-action-item icon="visibility" wire:click="viewDetail({{ $ret->id }})" aria-label="View Details">
                                        View Details
                                    </x-ui.table-action-item>
                                    @if($ret->isDraft())
                                        <x-ui.table-action-item icon="send" wire:click="postReturn({{ $ret->id }})" wire:confirm="Post this return? Physical stock will be deducted immediately from the branch." aria-label="Post Return">
                                            Post Return
                                        </x-ui.table-action-item>
                                        <x-ui.table-action-item icon="cancel" variant="destructive" wire:click="cancelReturn({{ $ret->id }})" wire:confirm="Cancel this draft return?" aria-label="Cancel Draft">
                                            Cancel Draft
                                        </x-ui.table-action-item>
                                    @endif
                                    @if($ret->isPosted() && auth()->user()->role?->name === 'Owner')
                                        <x-ui.table-action-item icon="undo" variant="destructive" wire:click="openReverseModal({{ $ret->id }})" aria-label="Reverse Return">
                                            Reverse Return
                                        </x-ui.table-action-item>
                                    @endif
                                </x-ui.table-actions>
                            </td>
                        </tr>
                @endforeach
                @if($returns->hasPages())
                    <x-slot:pagination>
                        {{ $returns->links() }}
                    </x-slot:pagination>
                @endif
    </x-erp.data-table>

    {{-- Return Draft Form Modal --}}
    <x-ui.modal wire:model="showFormModal" maxWidth="4xl">
        <x-slot:header>
            <h3 class="text-lg font-bold text-foreground">
                {{ $isEditing ? 'Edit Supplier Return Draft' : 'New Supplier Return' }}
            </h3>
        </x-slot:header>
        <x-slot:content>
            @if ($errors->has('general'))
                <x-ui.alert variant="destructive" class="mb-4">
                    {{ $errors->first('general') }}
                </x-ui.alert>
            @endif

            <form wire:submit="saveDraft" class="flex flex-col gap-4">
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-xs font-bold text-foreground uppercase mb-1">Supplier Name</label>
                        <input type="text" wire:model="supplier_name" placeholder="e.g. Acme Corp"
                               class="form-input w-full px-3 py-2 rounded-xl border border-border text-sm">
                        @error('supplier_name') <span class="text-rose-500 text-xs mt-1 block">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-foreground uppercase mb-1">GRN Reference (Optional)</label>
                        <select wire:model="goods_receipt_id" class="form-input w-full px-3 py-2 rounded-xl border border-border text-sm">
                            <option value="">-- No GRN Reference --</option>
                            @foreach($recentReceipts as $rec)
                                <option value="{{ $rec->id }}">{{ $rec->receipt_number }} ({{ $rec->supplier_reference ?? 'Vendor' }})</option>
                            @endforeach
                        </select>
                        @error('goods_receipt_id') <span class="text-rose-500 text-xs mt-1 block">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-foreground uppercase mb-1">Return Date</label>
                        <input type="datetime-local" wire:model="returned_at"
                               class="form-input w-full px-3 py-2 rounded-xl border border-border text-sm">
                        @error('returned_at') <span class="text-rose-500 text-xs mt-1 block">{{ $message }}</span> @enderror
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-bold text-foreground uppercase mb-1">Return Reason</label>
                        <input type="text" wire:model="reason" placeholder="e.g. Defective items, expired, or packaging damaged"
                               class="form-input w-full px-3 py-2 rounded-xl border border-border text-sm">
                        @error('reason') <span class="text-rose-500 text-xs mt-1 block">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-foreground uppercase mb-1">Additional Notes</label>
                        <input type="text" wire:model="notes" placeholder="Internal notes on return dispatch..."
                               class="form-input w-full px-3 py-2 rounded-xl border border-border text-sm">
                        @error('notes') <span class="text-rose-500 text-xs mt-1 block">{{ $message }}</span> @enderror
                    </div>
                </div>

                {{-- Return Item Lines --}}
                <div>
                    <div class="flex items-center justify-between mb-2">
                        <h5 class="text-sm font-bold text-foreground">Return Items</h5>
                        <button type="button" wire:click="addItemRow" class="text-xs font-bold text-foreground hover:underline flex items-center gap-1">
                            <span class="material-symbols-rounded text-[16px]">add</span> Add Line
                        </button>
                    </div>

                    <div class="border border-border rounded-2xl overflow-hidden">
                        <table class="table-standard w-full min-w-full text-left text-xs">
                            <thead class="bg-secondary text-muted-foreground font-bold border-b border-border">
                                <tr>
                                    <th class="px-3 py-2">Type</th>
                                    <th class="px-3 py-2">Item</th>
                                    <th class="px-3 py-2 w-28 text-right">Quantity</th>
                                    <th class="px-3 py-2 w-32 text-right">Unit Cost (Rp)</th>
                                    <th class="px-3 py-2">Notes</th>
                                    <th class="px-3 py-2 w-10 text-center"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border">
                                @foreach ($items as $index => $item)
                                    <tr class="align-top">
                                        <td class="p-2">
                                            <select wire:model.live="items.{{ $index }}.item_type" class="w-full text-xs rounded-lg border-border">
                                                <option value="product">Product</option>
                                                <option value="ingredient">Raw Material</option>
                                            </select>
                                        </td>
                                        <td class="p-2">
                                            @if (($item['item_type'] ?? '') === 'product')
                                                <select wire:model="items.{{ $index }}.product_id" class="w-full text-xs rounded-lg border-border">
                                                    <option value="">-- Select Product --</option>
                                                    @foreach ($products as $prod)
                                                        <option value="{{ $prod->id }}">{{ $prod->product_name }}</option>
                                                    @endforeach
                                                </select>
                                            @else
                                                <select wire:model="items.{{ $index }}.ingredient_id" class="w-full text-xs rounded-lg border-border">
                                                    <option value="">-- Select Raw Material --</option>
                                                    @foreach ($ingredients as $ing)
                                                        <option value="{{ $ing->id }}">{{ $ing->name }} ({{ $ing->unit }})</option>
                                                    @endforeach
                                                </select>
                                            @endif
                                        </td>
                                        <td class="p-2 text-right">
                                            <input type="number" step="0.0001" min="0.0001" wire:model="items.{{ $index }}.quantity"
                                                   class="w-full text-xs rounded-lg border-border text-right tabular-nums">
                                        </td>
                                        <td class="p-2 text-right">
                                            <input type="number" step="0.01" min="0" wire:model="items.{{ $index }}.unit_cost"
                                                   placeholder="0.00" class="w-full text-xs rounded-lg border-border text-right tabular-nums font-sans">
                                        </td>
                                        <td class="p-2">
                                            <input type="text" wire:model="items.{{ $index }}.reason" placeholder="Line reason"
                                                   class="w-full text-xs rounded-lg border-border">
                                        </td>
                                        <td class="p-2 text-center">
                                            <button type="button" wire:click="removeItemRow({{ $index }})" class="text-rose-500 hover:text-rose-700">
                                                <span class="material-symbols-rounded text-[20px]">delete</span>
                                            </button>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="flex items-center justify-end gap-3 pt-4 border-t border-border">
                    <x-ui.button type="button" variant="secondary" wire:click="closeFormModal">Cancel</x-ui.button>
                    <x-ui.button type="submit" variant="primary" x-on:click="window.erpMotion?.pulseHighlight($el)">Save Draft</x-ui.button>
                </div>
            </form>
        </x-slot:content>
    </x-ui.modal>

    {{-- Return Details Modal --}}
    <x-ui.modal wire:model="showDetailModal" maxWidth="3xl">
        <x-slot:header>
            @if ($selectedReturn)
                <div>
                    <h3 class="text-lg font-bold text-foreground">Return Details #{{ $selectedReturn->return_number }}</h3>
                    <p class="text-xs text-muted-foreground">Supplier: {{ $selectedReturn->supplier_name ?? '-' }}</p>
                </div>
            @endif
        </x-slot:header>
        <x-slot:content>
            @if ($selectedReturn)
                <div class="flex flex-col gap-4">
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 bg-secondary p-4 rounded-2xl text-xs">
                        <div>
                            <span class="text-muted-foreground block mb-0.5">Status:</span>
                            <span class="inline-flex items-center gap-1.5 text-xs text-foreground font-medium">
                                <span class="w-1.5 h-1.5 rounded-full {{ match($selectedReturn->status->value) { 'completed' => 'bg-success', 'draft' => 'bg-warning', 'cancelled' => 'bg-destructive', default => 'bg-neutral' } }}"></span>
                                {{ $selectedReturn->status->label() }}
                            </span>
                        </div>
                        <div>
                            <span class="text-muted-foreground block">Date:</span>
                            <span class="font-bold text-foreground">{{ $selectedReturn->returned_at->format('d M Y H:i') }}</span>
                        </div>
                        <div>
                            <span class="text-muted-foreground block">Total Value:</span>
                            <span class="font-bold text-foreground">Rp {{ number_format((float) $selectedReturn->calculateTotalValue(), 2) }}</span>
                        </div>
                        <div>
                            <span class="text-muted-foreground block">Created By:</span>
                            <span class="font-bold text-foreground">{{ $selectedReturn->creator?->name ?? 'System' }}</span>
                        </div>
                    </div>

                    @if($selectedReturn->reason)
                        <div class="text-xs p-3 rounded-xl bg-amber-50 border border-amber-200 text-amber-800">
                            <strong>Return Reason:</strong> {{ $selectedReturn->reason }}
                        </div>
                    @endif

                    <h5 class="text-sm font-bold text-foreground">Returned Items Breakdown</h5>
                    <div class="border border-border rounded-2xl overflow-hidden">
                        <table class="table-standard w-full min-w-full text-left text-xs">
                            <thead class="bg-secondary text-muted-foreground font-bold border-b border-border">
                                <tr>
                                    <th class="px-4 py-2">Item</th>
                                    <th class="px-4 py-2 text-right">Quantity</th>
                                    <th class="px-4 py-2 text-right">Unit Cost (Rp)</th>
                                    <th class="px-4 py-2 text-right">Total (Rp)</th>
                                    <th class="px-4 py-2">Notes</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border">
                                @foreach ($selectedReturn->items as $it)
                                    <tr>
                                        <td class="px-4 py-2.5 font-bold text-foreground">
                                            {{ $it->getItemName() }}
                                        </td>
                                        <td class="px-4 py-2.5 text-right font-sans font-bold">
                                            {{ number_format((float) $it->quantity, 4) }}
                                        </td>
                                        <td class="px-4 py-2.5 text-right font-sans">
                                            {{ number_format((float) $it->unit_cost, 2) }}
                                        </td>
                                        <td class="px-4 py-2.5 text-right font-sans font-bold text-emerald-700">
                                            {{ number_format((float) bcmul((string) $it->quantity, (string) $it->unit_cost, 2), 2) }}
                                        </td>
                                        <td class="px-4 py-2.5 text-muted-foreground">
                                            {{ $it->reason ?? '-' }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="flex items-center justify-end pt-4 border-t border-border mt-4">
                    <x-ui.button type="button" variant="secondary" wire:click="closeDetailModal">Close</x-ui.button>
                </div>
            @endif
        </x-slot:content>
    </x-ui.modal>

    {{-- Reversal Modal --}}
    <x-ui.modal wire:model="showReverseModal" maxWidth="md">
        <x-slot:header>
            <h3 class="text-lg font-bold text-foreground">Reverse Supplier Return</h3>
        </x-slot:header>
        <x-slot:content>
            <p class="text-xs text-muted-foreground mb-4">
                Reversing this return will restore the item quantities back into the branch active stock balance. This action is permanent and cannot be undone.
            </p>

            <div class="mb-4">
                <label class="block text-xs font-bold text-foreground uppercase mb-1">Reversal Reason</label>
                <textarea wire:model="reversal_reason" rows="3" placeholder="State the reason for this reversal..."
                          class="form-input w-full p-3 rounded-xl border border-border text-sm"></textarea>
                @error('reversal_reason') <span class="text-rose-500 text-xs mt-1 block">{{ $message }}</span> @enderror
            </div>

            <div class="flex items-center justify-end gap-3 pt-4 border-t border-border">
                <x-ui.button type="button" variant="secondary" wire:click="closeReverseModal">Cancel</x-ui.button>
                <x-ui.button type="button" variant="destructive" wire:click="submitReverse" x-on:click="window.erpMotion?.pulseHighlight($el)">Process Reversal</x-ui.button>
            </div>
        </x-slot:content>
    </x-ui.modal>
</div>
