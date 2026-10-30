<div data-gsap="page-enter">
    @include('inventory.partials.sub-nav')
    {{-- Header & Primary Action --}}
    <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between mb-6 gap-4">
        <div>
            <h4 class="text-xl font-bold text-apeiron-ink tracking-tight">Supplier Returns</h4>
            <p class="text-sm font-medium text-apeiron-muted mt-1">
                Manage product and raw material returns to vendors with automated stock deductions.
            </p>
        </div>
        <div class="flex flex-col sm:flex-row items-center gap-3 w-full sm:w-auto">
            <x-ui.button type="button" variant="primary" wire:click="openCreateModal" x-on:click="window.erpMotion?.pulseHighlight($el)">
                <span class="material-symbols-rounded text-[18px]">add</span> New Supplier Return
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
            <input type="text" wire:model.live.debounce.300ms="search" placeholder="Search return number, supplier name..."
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

    {{-- Supplier Returns Table --}}
    <div class="card-surface overflow-hidden w-full shrink-0">
        <div class="overflow-x-auto">
            <table class="table-standard w-full min-w-full text-left border-collapse text-sm">
                <thead>
                    <tr class="border-b border-apeiron-border bg-apeiron-surface text-apeiron-muted uppercase tracking-wider text-[11px] font-bold">
                        <th class="px-6 py-4">Return #</th>
                        <th class="px-6 py-4">Return Date</th>
                        <th class="px-6 py-4">Supplier</th>
                        <th class="px-6 py-4">Reference</th>
                        <th class="px-6 py-4">Status</th>
                        <th class="px-6 py-4">Created / Posted By</th>
                        <th class="px-6 py-4 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-apeiron-border">
                    @forelse ($returns as $ret)
                        <tr class="hover:bg-apeiron-surface/40 transition-colors">
                            <td class="px-6 py-4 font-bold text-apeiron-ink">
                                {{ $ret->return_number }}
                            </td>
                            <td class="px-6 py-4 text-apeiron-muted text-xs">
                                {{ $ret->returned_at->format('d M Y H:i') }}
                            </td>
                            <td class="px-6 py-4 font-medium text-apeiron-ink">
                                {{ $ret->supplier_name ?? '-' }}
                            </td>
                            <td class="px-6 py-4 text-xs text-apeiron-muted">
                                @if($ret->goodsReceipt)
                                    <span class="inline-flex items-center gap-1 font-mono text-emerald-700 bg-emerald-50 px-2 py-0.5 rounded">
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
                                <x-ui.badge :variant="$ret->status->badgeType()">
                                    {{ $ret->status->label() }}
                                </x-ui.badge>
                            </td>
                            <td class="px-6 py-4 text-xs text-apeiron-muted">
                                <div>Created: {{ $ret->creator?->name ?? 'System' }}</div>
                                @if($ret->poster)
                                    <div class="text-[11px] text-emerald-600">Posted: {{ $ret->poster->name }}</div>
                                @endif
                            </td>
                            <td class="px-6 py-4 text-right">
                                <div class="flex items-center justify-end gap-1.5">
                                    <button wire:click="viewDetail({{ $ret->id }})" title="View Details"
                                            class="p-1.5 text-apeiron-muted hover:text-apeiron-ink hover:bg-apeiron-surface rounded-lg transition-colors">
                                        <span class="material-symbols-rounded text-[18px]">visibility</span>
                                    </button>

                                    @if($ret->isDraft())
                                        <button wire:click="postReturn({{ $ret->id }})" title="Post Return"
                                                wire:confirm="Post this return? Physical stock will be deducted immediately from the branch."
                                                class="p-1.5 text-emerald-600 hover:text-emerald-800 hover:bg-emerald-50 rounded-lg transition-colors">
                                            <span class="material-symbols-rounded text-[18px]">send</span>
                                        </button>
                                        <button wire:click="cancelReturn({{ $ret->id }})" title="Cancel Draft"
                                                wire:confirm="Cancel this draft return?"
                                                class="p-1.5 text-rose-500 hover:text-rose-700 hover:bg-rose-50 rounded-lg transition-colors">
                                            <span class="material-symbols-rounded text-[18px]">cancel</span>
                                        </button>
                                    @endif

                                    @if($ret->isPosted() && auth()->user()->role?->name === 'Owner')
                                        <button wire:click="openReverseModal({{ $ret->id }})" title="Reverse Return"
                                                class="p-1.5 text-amber-600 hover:text-amber-800 hover:bg-amber-50 rounded-lg transition-colors">
                                            <span class="material-symbols-rounded text-[18px]">undo</span>
                                        </button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-6 py-12 text-center text-apeiron-muted">
                                <div class="flex flex-col items-center justify-center gap-2">
                                    <span class="material-symbols-rounded text-[40px] text-apeiron-muted/60">keyboard_return</span>
                                    <p class="font-medium text-sm">No supplier return documents found.</p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($returns->hasPages())
            <div class="px-6 py-4 border-t border-apeiron-border bg-apeiron-surface">
                {{ $returns->links() }}
            </div>
        @endif
    </div>

    {{-- Return Draft Form Modal --}}
    <x-ui.modal wire:model="showFormModal" maxWidth="4xl">
        <x-slot:header>
            <h3 class="text-lg font-bold text-apeiron-ink">
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
                        <label class="block text-xs font-bold text-apeiron-ink uppercase mb-1">Supplier Name</label>
                        <input type="text" wire:model="supplier_name" placeholder="e.g. Acme Corp"
                               class="form-input w-full px-3 py-2 rounded-xl border border-apeiron-border text-sm">
                        @error('supplier_name') <span class="text-rose-500 text-xs mt-1 block">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-apeiron-ink uppercase mb-1">GRN Reference (Optional)</label>
                        <select wire:model="goods_receipt_id" class="form-input w-full px-3 py-2 rounded-xl border border-apeiron-border text-sm">
                            <option value="">-- No GRN Reference --</option>
                            @foreach($recentReceipts as $rec)
                                <option value="{{ $rec->id }}">{{ $rec->receipt_number }} ({{ $rec->supplier_reference ?? 'Vendor' }})</option>
                            @endforeach
                        </select>
                        @error('goods_receipt_id') <span class="text-rose-500 text-xs mt-1 block">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-apeiron-ink uppercase mb-1">Return Date</label>
                        <input type="datetime-local" wire:model="returned_at"
                               class="form-input w-full px-3 py-2 rounded-xl border border-apeiron-border text-sm">
                        @error('returned_at') <span class="text-rose-500 text-xs mt-1 block">{{ $message }}</span> @enderror
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-bold text-apeiron-ink uppercase mb-1">Return Reason</label>
                        <input type="text" wire:model="reason" placeholder="e.g. Defective items, expired, or packaging damaged"
                               class="form-input w-full px-3 py-2 rounded-xl border border-apeiron-border text-sm">
                        @error('reason') <span class="text-rose-500 text-xs mt-1 block">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-apeiron-ink uppercase mb-1">Additional Notes</label>
                        <input type="text" wire:model="notes" placeholder="Internal notes on return dispatch..."
                               class="form-input w-full px-3 py-2 rounded-xl border border-apeiron-border text-sm">
                        @error('notes') <span class="text-rose-500 text-xs mt-1 block">{{ $message }}</span> @enderror
                    </div>
                </div>

                {{-- Return Item Lines --}}
                <div>
                    <div class="flex items-center justify-between mb-2">
                        <h5 class="text-sm font-bold text-apeiron-ink">Return Items</h5>
                        <button type="button" wire:click="addItemRow" class="text-xs font-bold text-apeiron-ink hover:underline flex items-center gap-1">
                            <span class="material-symbols-rounded text-[16px]">add</span> Add Line
                        </button>
                    </div>

                    <div class="border border-apeiron-border rounded-2xl overflow-hidden">
                        <table class="table-standard w-full min-w-full text-left text-xs">
                            <thead class="bg-apeiron-surface text-apeiron-muted font-bold border-b border-apeiron-border">
                                <tr>
                                    <th class="px-3 py-2">Type</th>
                                    <th class="px-3 py-2">Item</th>
                                    <th class="px-3 py-2 w-28 text-right">Quantity</th>
                                    <th class="px-3 py-2 w-32 text-right">Unit Cost (Rp)</th>
                                    <th class="px-3 py-2">Notes</th>
                                    <th class="px-3 py-2 w-10 text-center"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-apeiron-border">
                                @foreach ($items as $index => $item)
                                    <tr class="align-top">
                                        <td class="p-2">
                                            <select wire:model.live="items.{{ $index }}.item_type" class="w-full text-xs rounded-lg border-apeiron-border">
                                                <option value="product">Product</option>
                                                <option value="ingredient">Raw Material</option>
                                            </select>
                                        </td>
                                        <td class="p-2">
                                            @if (($item['item_type'] ?? '') === 'product')
                                                <select wire:model="items.{{ $index }}.product_id" class="w-full text-xs rounded-lg border-apeiron-border">
                                                    <option value="">-- Select Product --</option>
                                                    @foreach ($products as $prod)
                                                        <option value="{{ $prod->id }}">{{ $prod->product_name }}</option>
                                                    @endforeach
                                                </select>
                                            @else
                                                <select wire:model="items.{{ $index }}.ingredient_id" class="w-full text-xs rounded-lg border-apeiron-border">
                                                    <option value="">-- Select Raw Material --</option>
                                                    @foreach ($ingredients as $ing)
                                                        <option value="{{ $ing->id }}">{{ $ing->name }} ({{ $ing->unit }})</option>
                                                    @endforeach
                                                </select>
                                            @endif
                                        </td>
                                        <td class="p-2 text-right">
                                            <input type="number" step="0.0001" min="0.0001" wire:model="items.{{ $index }}.quantity"
                                                   class="w-full text-xs rounded-lg border-apeiron-border text-right tabular-nums">
                                        </td>
                                        <td class="p-2 text-right">
                                            <input type="number" step="0.01" min="0" wire:model="items.{{ $index }}.unit_cost"
                                                   placeholder="0.00" class="w-full text-xs rounded-lg border-apeiron-border text-right tabular-nums font-mono">
                                        </td>
                                        <td class="p-2">
                                            <input type="text" wire:model="items.{{ $index }}.reason" placeholder="Line reason"
                                                   class="w-full text-xs rounded-lg border-apeiron-border">
                                        </td>
                                        <td class="p-2 text-center">
                                            <button type="button" wire:click="removeItemRow({{ $index }})" class="text-rose-500 hover:text-rose-700">
                                                <span class="material-symbols-rounded text-[18px]">delete</span>
                                            </button>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="flex items-center justify-end gap-3 pt-4 border-t border-apeiron-border">
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
                    <h3 class="text-lg font-bold text-apeiron-ink">Return Details #{{ $selectedReturn->return_number }}</h3>
                    <p class="text-xs text-apeiron-muted">Supplier: {{ $selectedReturn->supplier_name ?? '-' }}</p>
                </div>
            @endif
        </x-slot:header>
        <x-slot:content>
            @if ($selectedReturn)
                <div class="flex flex-col gap-4">
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 bg-apeiron-surface p-4 rounded-2xl text-xs">
                        <div>
                            <span class="text-apeiron-muted block">Status:</span>
                            <x-ui.badge :variant="$selectedReturn->status->badgeType()">
                                {{ $selectedReturn->status->label() }}
                            </x-ui.badge>
                        </div>
                        <div>
                            <span class="text-apeiron-muted block">Date:</span>
                            <span class="font-bold text-apeiron-ink">{{ $selectedReturn->returned_at->format('d M Y H:i') }}</span>
                        </div>
                        <div>
                            <span class="text-apeiron-muted block">Total Value:</span>
                            <span class="font-bold text-apeiron-ink">Rp {{ number_format((float) $selectedReturn->calculateTotalValue(), 2) }}</span>
                        </div>
                        <div>
                            <span class="text-apeiron-muted block">Created By:</span>
                            <span class="font-bold text-apeiron-ink">{{ $selectedReturn->creator?->name ?? 'System' }}</span>
                        </div>
                    </div>

                    @if($selectedReturn->reason)
                        <div class="text-xs p-3 rounded-xl bg-amber-50 border border-amber-200 text-amber-800">
                            <strong>Return Reason:</strong> {{ $selectedReturn->reason }}
                        </div>
                    @endif

                    <h5 class="text-sm font-bold text-apeiron-ink">Returned Items Breakdown</h5>
                    <div class="border border-apeiron-border rounded-2xl overflow-hidden">
                        <table class="table-standard w-full min-w-full text-left text-xs">
                            <thead class="bg-apeiron-surface text-apeiron-muted font-bold border-b border-apeiron-border">
                                <tr>
                                    <th class="px-4 py-2">Item</th>
                                    <th class="px-4 py-2 text-right">Quantity</th>
                                    <th class="px-4 py-2 text-right">Unit Cost (Rp)</th>
                                    <th class="px-4 py-2 text-right">Total (Rp)</th>
                                    <th class="px-4 py-2">Notes</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-apeiron-border">
                                @foreach ($selectedReturn->items as $it)
                                    <tr>
                                        <td class="px-4 py-2.5 font-bold text-apeiron-ink">
                                            {{ $it->getItemName() }}
                                        </td>
                                        <td class="px-4 py-2.5 text-right font-mono font-bold">
                                            {{ number_format((float) $it->quantity, 4) }}
                                        </td>
                                        <td class="px-4 py-2.5 text-right font-mono">
                                            {{ number_format((float) $it->unit_cost, 2) }}
                                        </td>
                                        <td class="px-4 py-2.5 text-right font-mono font-bold text-emerald-700">
                                            {{ number_format((float) bcmul((string) $it->quantity, (string) $it->unit_cost, 2), 2) }}
                                        </td>
                                        <td class="px-4 py-2.5 text-apeiron-muted">
                                            {{ $it->reason ?? '-' }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="flex items-center justify-end pt-4 border-t border-apeiron-border mt-4">
                    <x-ui.button type="button" variant="secondary" wire:click="closeDetailModal">Close</x-ui.button>
                </div>
            @endif
        </x-slot:content>
    </x-ui.modal>

    {{-- Reversal Modal --}}
    <x-ui.modal wire:model="showReverseModal" maxWidth="md">
        <x-slot:header>
            <h3 class="text-lg font-bold text-apeiron-ink">Reverse Supplier Return</h3>
        </x-slot:header>
        <x-slot:content>
            <p class="text-xs text-apeiron-muted mb-4">
                Reversing this return will restore the item quantities back into the branch active stock balance. This action is permanent and cannot be undone.
            </p>

            <div class="mb-4">
                <label class="block text-xs font-bold text-apeiron-ink uppercase mb-1">Reversal Reason</label>
                <textarea wire:model="reversal_reason" rows="3" placeholder="State the reason for this reversal..."
                          class="form-input w-full p-3 rounded-xl border border-apeiron-border text-sm"></textarea>
                @error('reversal_reason') <span class="text-rose-500 text-xs mt-1 block">{{ $message }}</span> @enderror
            </div>

            <div class="flex items-center justify-end gap-3 pt-4 border-t border-apeiron-border">
                <x-ui.button type="button" variant="secondary" wire:click="closeReverseModal">Cancel</x-ui.button>
                <x-ui.button type="button" variant="destructive" wire:click="submitReverse" x-on:click="window.erpMotion?.pulseHighlight($el)">Process Reversal</x-ui.button>
            </div>
        </x-slot:content>
    </x-ui.modal>
</div>
