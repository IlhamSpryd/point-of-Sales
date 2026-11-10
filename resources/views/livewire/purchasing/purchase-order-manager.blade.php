<div class="space-y-6" data-gsap="page-enter">
    @include('purchasing.partials.sub-nav')

    <!-- Header -->
    <x-erp.page-header
        title="Purchase Orders"
        description="Manage procurement purchase orders to vendors, approval workflows, and operational fulfillment status.">
        <x-slot:actions>
            @can('create', \App\Modules\Purchasing\Models\PurchaseOrder::class)
            <x-ui.button wire:click="openCreateModal" icon="add" variant="primary">
                New Purchase Order
            </x-ui.button>
            @endcan
        </x-slot:actions>
    </x-erp.page-header>

    <!-- Flash Messages -->
    @if (session()->has('message'))
        <x-ui.alert variant="success" icon="check_circle">
            {{ session('message') }}
        </x-ui.alert>
    @endif

    @if (session()->has('error'))
        <x-ui.alert variant="danger" icon="error">
            {{ session('error') }}
        </x-ui.alert>
    @endif

    <!-- Filter & Toolbar -->
    <div class="card-surface p-4 rounded-2xl flex flex-col md:flex-row md:items-center justify-between gap-4 border border-border">
        <div class="flex-1 max-w-md">
            <x-ui.input
                type="text"
                wire:model.live.debounce.300ms="search"
                placeholder="Search PO number or supplier name..."
                icon="search" />
        </div>
        <div class="flex flex-wrap items-center gap-3">
            <x-ui.select wire:model.live="supplierFilter">
                <option value="">All Suppliers</option>
                @foreach($suppliers as $s)
                    <option value="{{ $s->id }}">{{ $s->name }}</option>
                @endforeach
            </x-ui.select>

            <x-ui.select wire:model.live="statusFilter">
                <option value="">All Statuses</option>
                <option value="DRAFT">Draft</option>
                <option value="PENDING_APPROVAL">Pending Approval</option>
                <option value="APPROVED">Approved</option>
                <option value="ISSUED">Issued</option>
                <option value="PARTIALLY_RECEIVED">Partially Received</option>
                <option value="COMPLETED">Completed</option>
                <option value="CANCELLED">Cancelled</option>
            </x-ui.select>
        </div>
    </div>

    @php
        $poColumns = [
            ['key' => 'po_number', 'label' => 'PO # & Date', 'sortable' => true],
            ['key' => 'supplier_id', 'label' => 'Supplier'],
            ['key' => 'store_id', 'label' => 'Branch'],
            ['key' => 'total_amount', 'label' => 'Total Amount', 'numeric' => true, 'sortable' => true],
            ['key' => 'receiving_status', 'label' => 'Receipt Status', 'align' => 'center'],
            ['key' => 'invoicing_status', 'label' => 'Invoice Match', 'align' => 'center'],
            ['key' => 'status', 'label' => 'PO Status', 'align' => 'center', 'sortable' => true],
            ['key' => 'actions', 'label' => 'Actions', 'align' => 'right'],
        ];
    @endphp

    <!-- Data Table -->
    <x-erp.data-table
        :columns="$poColumns"
        :rows="$purchaseOrders"
        :sort-field="$sortField"
        :sort-direction="$sortDirection"
        loading-target="search, statusFilter, supplierFilter, sortBy"
        :is-empty="$purchaseOrders->isEmpty()"
        empty-title="No purchase orders found"
        empty-description="Create a new purchase order to start the procurement process."
        empty-icon="receipt_long"
        :colspan="8"
    >
        @foreach($purchaseOrders as $po)
                    <tr class="hover:bg-muted/40 transition-colors">
                        <td class="px-6 py-4">
                            <div class="font-bold text-foreground font-sans">{{ $po->po_number }}</div>
                            <div class="text-xs text-muted-foreground">{{ \Carbon\Carbon::parse($po->order_date)->format('d M Y') }}</div>
                        </td>
                        <td class="px-6 py-4">
                            <div class="font-medium text-foreground">{{ $po->supplier?->name ?? 'Vendor #' . $po->supplier_id }}</div>
                            <div class="text-xs text-muted-foreground">{{ $po->supplier?->phone ?: $po->supplier?->email }}</div>
                        </td>
                        <td class="px-6 py-4">
                            <span class="text-foreground font-medium">{{ $po->store?->name ?? 'All Branches' }}</span>
                        </td>
                        <td class="px-6 py-4 text-right">
                            <div class="font-bold text-foreground">Rp {{ number_format((float) $po->total_amount, 2) }}</div>
                            <div class="text-[11px] text-muted-foreground">Tax: Rp {{ number_format((float) $po->tax_amount, 2) }}</div>
                        </td>
                        <td class="px-6 py-4">
                            <span class="text-xs text-muted-foreground font-medium">
                                {{ $po->receiving_status?->label() ?? $po->receiving_status ?? 'UNRECEIVED' }}
                            </span>
                        </td>
                        <td class="px-6 py-4">
                            <span class="text-xs text-muted-foreground font-medium">
                                {{ $po->invoicing_status?->label() ?? $po->invoicing_status ?? 'UNINVOICED' }}
                            </span>
                        </td>
                        <td class="px-6 py-4">
                            @php
                                $poStatusVal = $po->status?->value ?? (string) $po->status;
                                $statusDot = match($poStatusVal) {
                                    'APPROVED', 'approved' => 'bg-info',
                                    'ISSUED', 'released' => 'bg-primary',
                                    'COMPLETED', 'closed' => 'bg-success',
                                    'CANCELLED', 'cancelled' => 'bg-destructive',
                                    'PENDING_APPROVAL', 'pending_approval' => 'bg-warning',
                                    default => 'bg-neutral'
                                };
                            @endphp
                            <span class="inline-flex items-center gap-1.5 text-xs text-foreground font-medium">
                                <span class="w-1.5 h-1.5 rounded-full {{ $statusDot }}"></span>
                                {{ $po->status?->label() ?? $poStatusVal }}
                            </span>
                        </td>
                        <td class="px-6 py-4 text-right whitespace-nowrap">
                            <x-ui.table-actions>
                                <x-ui.table-action-item icon="visibility" wire:click="viewPo({{ $po->id }})">
                                    View PO Details
                                </x-ui.table-action-item>
                                @if($po->isDraft())
                                    <x-ui.table-action-item icon="send" wire:click="submitPo({{ $po->id }})">
                                        Submit for Approval
                                    </x-ui.table-action-item>
                                @endif
                                @if($po->status?->value === 'APPROVED' || $po->status === 'approved')
                                    <x-ui.table-action-item icon="check_circle" wire:click="releasePo({{ $po->id }})">
                                        Issue to Supplier
                                    </x-ui.table-action-item>
                                @endif
                                @if($po->canBeCancelled())
                                    <x-ui.table-action-item icon="cancel" variant="destructive" wire:click="confirmCancelPo({{ $po->id }})">
                                        Cancel PO
                                    </x-ui.table-action-item>
                                @endif
                            </x-ui.table-actions>
                        </td>
                    </tr>
        @endforeach
    </x-erp.data-table>

    <!-- Modal Buat Draf PO Baru -->
    <x-ui.modal wire:model="showCreateModal" maxWidth="4xl">
        <x-slot:title>
            New Purchase Order (Direct PO)
        </x-slot:title>

        <x-slot:content>
            <form id="createPoForm" wire:submit="savePurchaseOrder" class="space-y-6">
                <x-erp.dirty-guard watch="items" />
                <!-- Header Info -->
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4 p-4 rounded-xl bg-zinc-50 dark:bg-zinc-900/40 border border-zinc-200 dark:border-zinc-800">
                    <div>
                        <x-erp.form-field label="Supplier" required :error="$errors->first('supplierId')">
                            <x-erp.searchable-select
                                id="supplierId"
                                wire:model.live="supplierId"
                                :options="collect($suppliers)->map(fn($s) => ['value' => (string) $s->id, 'label' => $s->name . ' (' . $s->code . ')'])->all()"
                                placeholder="-- Select Supplier --"
                            />
                        </x-erp.form-field>
                    </div>

                    <div>
                        <x-ui.label for="storeId">Destination Branch *</x-ui.label>
                        <x-ui.select id="storeId" wire:model="storeId" required>
                            @foreach($stores as $st)
                                <option value="{{ $st->id }}">{{ $st->name }}</option>
                            @endforeach
                        </x-ui.select>
                        <x-input-error :messages="$errors->get('storeId')" class="mt-1" />
                    </div>

                    <div>
                        <x-ui.label for="orderDate">Order Date *</x-ui.label>
                        <x-ui.input type="date" id="orderDate" wire:model="orderDate" required />
                        <x-input-error :messages="$errors->get('orderDate')" class="mt-1" />
                    </div>

                    <div>
                        <x-ui.label for="expectedDeliveryDate">Expected Delivery Date</x-ui.label>
                        <x-ui.input type="date" id="expectedDeliveryDate" wire:model="expectedDeliveryDate" />
                        <x-input-error :messages="$errors->get('expectedDeliveryDate')" class="mt-1" />
                    </div>
                </div>

                <!-- Lines Table -->
                <div class="space-y-3">
                    <div class="flex items-center justify-between">
                        <h4 class="text-xs font-bold uppercase tracking-wider text-zinc-500">PO Line Items</h4>
                        <x-ui.button type="button" size="sm" variant="secondary" wire:click="addItem" icon="add">
                            Add Line
                        </x-ui.button>
                    </div>

                    <div class="overflow-x-auto border border-zinc-200 dark:border-zinc-800 rounded-xl">
                        <table class="table-standard w-full min-w-full text-left text-xs">
                            <thead class="bg-zinc-100 dark:bg-zinc-800/80 font-semibold text-zinc-600 dark:text-zinc-300">
                                <tr>
                                    <th class="px-3 py-2 w-28">Type</th>
                                    <th class="px-3 py-2">Master Item</th>
                                    <th class="px-3 py-2 w-24">UOM</th>
                                    <th class="px-3 py-2 w-20">Conversion</th>
                                    <th class="px-3 py-2 w-24 text-right">Quantity</th>
                                    <th class="px-3 py-2 w-32 text-right">Unit Price</th>
                                    <th class="px-3 py-2 w-10 text-center"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                                @foreach($items as $i => $item)
                                <tr>
                                    <td class="px-3 py-2">
                                        <select wire:model.live="items.{{ $i }}.purchasable_type" wire:change="onItemTypeChanged({{ $i }})" class="w-full text-xs rounded-lg border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-900 py-1">
                                            <option value="product">Retail Product</option>
                                            <option value="ingredient">Raw Material</option>
                                        </select>
                                    </td>
                                    <td class="px-3 py-2">
                                        @php
                                            $poItemOptions = (($item['purchasable_type'] ?? 'product') === 'product')
                                                ? collect($products)->map(fn($p) => ['value' => (string) $p->id, 'label' => $p->product_name])->all()
                                                : collect($ingredients)->map(fn($ing) => ['value' => (string) $ing->id, 'label' => $ing->name . ' (' . $ing->unit . ')'])->all();
                                        @endphp
                                        <x-erp.searchable-select
                                            wire:model.live="items.{{ $i }}.purchasable_id"
                                            :options="$poItemOptions"
                                            placeholder="-- Select Item --"
                                            @change="$wire.onItemSelected({{ $i }})"
                                        />
                                    </td>
                                    <td class="px-3 py-2">
                                        <input type="text" wire:model="items.{{ $i }}.purchase_uom" class="w-full text-xs rounded-lg border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-900 py-1 uppercase" />
                                    </td>
                                    <td class="px-3 py-2">
                                        <input type="number" step="0.0001" wire:model="items.{{ $i }}.uom_conversion_factor" class="w-full text-xs rounded-lg border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-900 py-1 tabular-nums text-right" />
                                    </td>
                                    <td class="px-3 py-2 text-right">
                                        <input type="number" step="0.0001" min="0.0001" wire:model="items.{{ $i }}.ordered_qty" class="w-full text-xs rounded-lg border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-900 py-1 text-right font-medium tabular-nums" />
                                    </td>
                                    <td class="px-3 py-2 text-right">
                                        <input type="number" step="0.01" min="0" wire:model="items.{{ $i }}.unit_price" class="w-full text-xs rounded-lg border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-900 py-1 text-right font-medium tabular-nums" />
                                    </td>
                                    <td class="px-3 py-2 text-center">
                                        <button type="button" wire:click="removeItem({{ $i }})" class="p-1 text-zinc-400 hover:text-rose-500">
                                            <span class="material-symbols-rounded text-sm">close</span>
                                        </button>
                                    </td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>

                <div>
                    <x-ui.label for="notes">PO Notes</x-ui.label>
                    <textarea id="notes" wire:model="notes" rows="2" placeholder="Delivery instructions, warehouse contact, payment terms..." class="mt-1 w-full rounded-xl border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-3 py-2 text-sm"></textarea>
                </div>
            </form>
        </x-slot:content>

        <x-slot:footer>
            <div class="flex items-center justify-end gap-3">
                <x-ui.button type="button" variant="secondary" wire:click="$set('showCreateModal', false)">Cancel</x-ui.button>
                <x-ui.button type="submit" form="createPoForm" variant="primary" wire:loading.attr="disabled">
                    <span wire:loading.remove>Save PO Draft</span>
                    <span wire:loading>Saving...</span>
                </x-ui.button>
            </div>
        </x-slot:footer>
    </x-ui.modal>

    <!-- Modal Detail PO -->
    <x-ui.modal wire:model="showDetailModal" maxWidth="3xl">
        <x-slot:title>
            @if($selectedPo)
                <div>
                    <span class="font-sans">{{ $selectedPo->po_number }}</span>
                    <span class="ml-2 inline-flex items-center gap-1.5 text-xs text-foreground font-medium font-sans">
                        <span class="w-1.5 h-1.5 rounded-full bg-primary"></span>
                        {{ $selectedPo->status?->label() ?? $selectedPo->status }}
                    </span>
                </div>
            @else
                Purchase Order Details
            @endif
        </x-slot:title>

        <x-slot:content>
            @if($selectedPo)
            <div class="space-y-6">
                <!-- Info Header -->
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4 p-4 rounded-xl bg-zinc-50 dark:bg-zinc-900/50 border border-zinc-200 dark:border-zinc-800 text-xs">
                    <div>
                        <div class="text-zinc-400 uppercase font-bold text-[10px]">Supplier</div>
                        <div class="font-semibold text-zinc-800 dark:text-zinc-200 mt-0.5">{{ $selectedPo->supplier?->name }}</div>
                        <div class="text-zinc-500 font-sans">{{ $selectedPo->supplier?->code }}</div>
                    </div>
                    <div>
                        <div class="text-zinc-400 uppercase font-bold text-[10px]">Branch</div>
                        <div class="font-semibold text-zinc-800 dark:text-zinc-200 mt-0.5">{{ $selectedPo->store?->name }}</div>
                    </div>
                    <div>
                        <div class="text-zinc-400 uppercase font-bold text-[10px]">PO Date</div>
                        <div class="font-semibold text-zinc-800 dark:text-zinc-200 mt-0.5">{{ \Carbon\Carbon::parse($selectedPo->order_date)->format('d M Y') }}</div>
                    </div>
                    <div>
                        <div class="text-zinc-400 uppercase font-bold text-[10px]">Expected Delivery</div>
                        <div class="font-semibold text-zinc-800 dark:text-zinc-200 mt-0.5">
                            {{ $selectedPo->expected_delivery_date ? \Carbon\Carbon::parse($selectedPo->expected_delivery_date)->format('d M Y') : '-' }}
                        </div>
                    </div>
                </div>

                <!-- Lines Table -->
                <div class="space-y-2">
                    <h4 class="text-xs font-bold uppercase tracking-wider text-zinc-500">Line Items Breakdown</h4>
                    <div class="overflow-x-auto border border-zinc-200 dark:border-zinc-800 rounded-xl">
                        <table class="table-standard w-full min-w-full text-left text-xs">
                            <thead class="bg-zinc-50 dark:bg-zinc-900/50 font-semibold text-zinc-500 border-b border-zinc-200 dark:border-zinc-800">
                                <tr>
                                    <th class="px-4 py-2.5">Item</th>
                                    <th class="px-4 py-2.5">UOM</th>
                                    <th class="px-4 py-2.5 text-right">Ordered</th>
                                    <th class="px-4 py-2.5 text-right">Received</th>
                                    <th class="px-4 py-2.5 text-right">Unit Price</th>
                                    <th class="px-4 py-2.5 text-right">Subtotal</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                                @foreach($selectedPo->lines as $line)
                                <tr>
                                    <td class="px-4 py-2.5">
                                        <div class="font-medium text-zinc-800 dark:text-zinc-200">
                                            {{ $line->purchasable?->product_name ?? $line->purchasable?->name ?? 'Item #' . $line->purchasable_id }}
                                        </div>
                                        <div class="text-[10px] text-zinc-400 uppercase">{{ $line->purchasable_type }}</div>
                                    </td>
                                    <td class="px-4 py-2.5 font-sans uppercase">{{ $line->purchase_uom }}</td>
                                    <td class="px-4 py-2.5 text-right font-medium tabular-nums">{{ (float) $line->ordered_qty }}</td>
                                    <td class="px-4 py-2.5 text-right font-medium text-emerald-600 tabular-nums">{{ (float) $line->received_qty }}</td>
                                    <td class="px-4 py-2.5 text-right tabular-nums">Rp {{ number_format((float) $line->unit_price, 2) }}</td>
                                    <td class="px-4 py-2.5 text-right font-semibold tabular-nums">Rp {{ number_format((float) $line->line_total, 2) }}</td>
                                </tr>
                                @endforeach
                            </tbody>
                            <tfoot class="bg-zinc-50 dark:bg-zinc-900/50 font-semibold border-t border-zinc-200 dark:border-zinc-800">
                                <tr>
                                    <td colspan="5" class="px-4 py-2 text-right text-zinc-500">Total PO Value:</td>
                                    <td class="px-4 py-2 text-right text-zinc-900 dark:text-white font-bold text-sm tabular-nums">
                                        Rp {{ number_format((float) $selectedPo->total_amount, 2) }}
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>

                @if($selectedPo->notes)
                <div class="text-xs p-3 rounded-lg bg-zinc-50 dark:bg-zinc-900/40 text-zinc-600 dark:text-zinc-400 border border-zinc-200 dark:border-zinc-800">
                    <span class="font-bold">Notes:</span> {{ $selectedPo->notes }}
                </div>
                @endif
            </div>
            @endif
        </x-slot:content>

        <x-slot:footer>
            <div class="flex items-center justify-between">
                <div>
                    @if($selectedPo && $selectedPo->canBeCancelled())
                        <x-ui.button type="button" variant="danger" wire:click="confirmCancelPo({{ $selectedPo->id }})">
                            Cancel PO
                        </x-ui.button>
                    @endif
                </div>
                <div class="flex items-center gap-2">
                    @if($selectedPo && $selectedPo->isDraft())
                        <x-ui.button type="button" variant="primary" wire:click="submitPo({{ $selectedPo->id }})">
                            Submit for Approval
                        </x-ui.button>
                    @endif
                    @if($selectedPo && ($selectedPo->status?->value === 'APPROVED' || $selectedPo->status === 'approved'))
                        <x-ui.button type="button" variant="primary" wire:click="releasePo({{ $selectedPo->id }})">
                            Issue to Supplier
                        </x-ui.button>
                    @endif
                    <x-ui.button type="button" variant="secondary" wire:click="$set('showDetailModal', false)">Close</x-ui.button>
                </div>
            </div>
        </x-slot:footer>
    </x-ui.modal>

    <!-- Cancel Purchase Order Confirmation Modal -->
    <x-ui.modal wire:model="showCancelModal" maxWidth="md">
        <x-slot:title>
            Confirm PO Cancellation
        </x-slot:title>

        <x-slot:content>
            <div class="space-y-3">
                <p class="text-sm text-zinc-600 dark:text-zinc-300">
                    Are you sure you want to cancel this Purchase Order? This action cannot be undone.
                </p>
                <div>
                    <x-ui.label for="cancelReason">Cancellation Reason</x-ui.label>
                    <x-ui.input type="text" id="cancelReason" wire:model="cancelReason" placeholder="e.g. Quantity error / vendor out of stock" />
                </div>
            </div>
        </x-slot:content>

        <x-slot:footer>
            <div class="flex justify-end gap-2">
                <x-ui.button type="button" variant="secondary" wire:click="$set('showCancelModal', false)">Close</x-ui.button>
                <x-ui.button type="button" variant="danger" wire:click="cancelPo">Cancel PO</x-ui.button>
            </div>
        </x-slot:footer>
    </x-ui.modal>
</div>
