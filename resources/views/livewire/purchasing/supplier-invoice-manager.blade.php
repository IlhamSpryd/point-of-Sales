<div class="space-y-6" data-gsap="page-enter">
    @include('purchasing.partials.sub-nav')

    <!-- Header -->
    <x-erp.page-header
        title="Supplier Invoices & 3-Way Match (Accounts Payable)"
        description="Three-way matching workbench (PO vs GRN vs Supplier Invoice), price and quantity variance tolerance detection, and accounts payable approval.">
        <x-slot:actions>
            @can('create', \App\Modules\Purchasing\Models\SupplierInvoice::class)
            <x-ui.button wire:click="openCreateModal" icon="receipt_long" variant="primary">
                Record Supplier Invoice
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
    <div class="card-surface p-4 rounded-2xl flex flex-col md:flex-row md:items-center justify-between gap-4 border border-[#E5E7EB] dark:border-zinc-800">
        <div class="flex-1 max-w-md">
            <x-ui.input
                type="text"
                wire:model.live.debounce.300ms="search"
                placeholder="Search supplier invoice number or internal reference..."
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
                <option value="MATCHED">3-Way Matched</option>
                <option value="VARIANCE_HELD">Variance Held</option>
                <option value="APPROVED_FOR_PAYMENT">Approved for Payment (AP Posted)</option>
                <option value="CANCELLED">Cancelled</option>
            </x-ui.select>
        </div>
    </div>

    <!-- Data Table -->
    <div class="card-surface rounded-2xl overflow-hidden border border-[#E5E7EB] dark:border-zinc-800 shadow-sm">
        <div class="overflow-x-auto">
            <table class="table-standard w-full min-w-full text-left text-sm text-zinc-600 dark:text-zinc-300">
                <thead class="bg-zinc-50 dark:bg-zinc-900/50 text-xs font-semibold uppercase text-zinc-500 border-b border-zinc-200 dark:border-zinc-800">
                    <tr>
                        <th class="px-6 py-4">Invoice Number</th>
                        <th class="px-6 py-4">Supplier</th>
                        <th class="px-6 py-4">Invoice Date / Due Date</th>
                        <th class="px-6 py-4 text-right">Total Invoiced</th>
                        <th class="px-6 py-4 text-center">3-Way Match Status</th>
                        <th class="px-6 py-4 text-center">Payment Status</th>
                        <th class="px-6 py-4 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                    @forelse($invoices as $inv)
                    <tr class="hover:bg-zinc-50/50 dark:hover:bg-zinc-800/40 transition-colors">
                        <td class="px-6 py-4">
                            <div class="font-bold text-zinc-900 dark:text-white font-mono">{{ $inv->supplier_invoice_number }}</div>
                            <div class="text-xs text-zinc-400 font-mono">Internal: {{ $inv->internal_invoice_number }}</div>
                        </td>
                        <td class="px-6 py-4">
                            <div class="font-medium text-zinc-800 dark:text-zinc-200">{{ $inv->supplier?->name ?? 'Vendor' }}</div>
                            <div class="text-xs text-zinc-400 font-mono">PO: {{ $inv->purchaseOrder?->po_number ?? '-' }}</div>
                        </td>
                        <td class="px-6 py-4">
                            <div class="font-medium text-zinc-700 dark:text-zinc-300">{{ \Carbon\Carbon::parse($inv->invoice_date)->format('d M Y') }}</div>
                            <div class="text-xs text-zinc-400">Due Date: {{ \Carbon\Carbon::parse($inv->due_date)->format('d M Y') }}</div>
                        </td>
                        <td class="px-6 py-4 text-right">
                            <div class="font-bold text-zinc-900 dark:text-white">Rp {{ number_format((float) $inv->total_amount, 2) }}</div>
                            <div class="text-[11px] text-zinc-400">Tax: Rp {{ number_format((float) $inv->tax_amount, 2) }}</div>
                        </td>
                        <td class="px-6 py-4 text-center">
                            @php
                                $matchVal = $inv->matching_status?->value ?? (string) $inv->matching_status;
                                $matchBadge = match($matchVal) {
                                    'MATCHED', 'matched' => 'success',
                                    'VARIANCE_HELD', 'variance_held' => 'warning',
                                    'FAILED', 'failed' => 'danger',
                                    default => 'neutral'
                                };
                            @endphp
                            <x-ui.badge :variant="$matchBadge">
                                {{ $inv->matching_status?->label() ?? $matchVal }}
                            </x-ui.badge>
                        </td>
                        <td class="px-6 py-4 text-center">
                            @php
                                $invStatusVal = $inv->status?->value ?? (string) $inv->status;
                                $statusBadge = match($invStatusVal) {
                                    'APPROVED_FOR_PAYMENT', 'approved_for_payment' => 'success',
                                    'PAID', 'paid' => 'info',
                                    'CANCELLED', 'cancelled' => 'danger',
                                    default => 'neutral'
                                };
                            @endphp
                            <x-ui.badge :variant="$statusBadge">
                                {{ $inv->status?->label() ?? $invStatusVal }}
                            </x-ui.badge>
                        </td>
                        <td class="px-6 py-4 text-right">
                            <div class="flex items-center justify-end gap-1">
                                <x-ui.button
                                    size="icon"
                                    variant="ghost"
                                    wire:click="viewInvoice({{ $inv->id }})"
                                    title="View Invoice Details"
                                    aria-label="Details">
                                    <span class="material-symbols-rounded text-base text-zinc-600 dark:text-zinc-400">visibility</span>
                                </x-ui.button>
                                @if(! $inv->isApprovedPayable() && ! $inv->isCancelled())
                                    <x-ui.button
                                        size="icon"
                                        variant="ghost"
                                        wire:click="openMatchWorkbench({{ $inv->id }})"
                                        title="3-Way Match Workbench"
                                        aria-label="Match">
                                        <span class="material-symbols-rounded text-base text-amber-600">compare_arrows</span>
                                    </x-ui.button>
                                @endif
                                @if(($inv->matching_status?->value === 'MATCHED' || $inv->matching_status === 'matched') && ! $inv->isApprovedPayable())
                                    <x-ui.button
                                        size="icon"
                                        variant="ghost"
                                        wire:click="approvePayable({{ $inv->id }})"
                                        title="Approve for Payment (Post AP & GL)"
                                        aria-label="Approve">
                                        <span class="material-symbols-rounded text-base text-emerald-600">verified</span>
                                    </x-ui.button>
                                @endif
                            </div>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="7" class="px-6 py-12 text-center">
                            <div class="flex flex-col items-center justify-center">
                                <span class="material-symbols-rounded text-5xl text-zinc-300 dark:text-zinc-700">receipt</span>
                                <h3 class="mt-2 text-sm font-semibold text-zinc-900 dark:text-white">No supplier invoices found</h3>
                                <p class="mt-1 text-xs text-zinc-500">Record supplier invoices to perform three-way matching against Purchase Orders and Goods Received Notes.</p>
                            </div>
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($invoices->hasPages())
        <div class="px-6 py-4 border-t border-zinc-200 dark:border-zinc-800">
            {{ $invoices->links() }}
        </div>
        @endif
    </div>

    <!-- Modal Catat Faktur Baru -->
    <x-ui.modal wire:model="showCreateModal" maxWidth="4xl">
        <x-slot:title>
            Record Supplier Invoice
        </x-slot:title>

        <x-slot:content>
            <form id="createInvoiceForm" wire:submit="saveInvoice" class="space-y-6">
                <!-- Info Header -->
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4 p-4 rounded-xl bg-zinc-50 dark:bg-zinc-900/40 border border-zinc-200 dark:border-zinc-800">
                    <div class="md:col-span-2">
                        <x-ui.label for="selectedPoId">Purchase Order (PO) Reference *</x-ui.label>
                        <x-ui.select id="selectedPoId" wire:model.live="selectedPoId" required>
                            <option value="">-- Select Related PO --</option>
                            @foreach($availablePos as $po)
                                <option value="{{ $po->id }}">{{ $po->po_number }} &bull; {{ $po->supplier?->name }} (Rp {{ number_format((float) $po->total_amount, 0) }})</option>
                            @endforeach
                        </x-ui.select>
                        <x-input-error :messages="$errors->get('selectedPoId')" class="mt-1" />
                    </div>

                    <div>
                        <x-ui.label for="supplierInvoiceNumber">Supplier Invoice Number *</x-ui.label>
                        <x-ui.input type="text" id="supplierInvoiceNumber" wire:model="supplierInvoiceNumber" placeholder="INV-2026/089" required />
                        <x-input-error :messages="$errors->get('supplierInvoiceNumber')" class="mt-1" />
                    </div>

                    <div>
                        <x-ui.label for="invoiceDate">Invoice Date *</x-ui.label>
                        <x-ui.input type="date" id="invoiceDate" wire:model="invoiceDate" required />
                        <x-input-error :messages="$errors->get('invoiceDate')" class="mt-1" />
                    </div>

                    <div>
                        <x-ui.label for="dueDate">Payment Due Date *</x-ui.label>
                        <x-ui.input type="date" id="dueDate" wire:model="dueDate" required />
                        <x-input-error :messages="$errors->get('dueDate')" class="mt-1" />
                    </div>
                </div>

                <!-- Lines Table -->
                @if(count($invoiceLines) > 0)
                <div class="space-y-3">
                    <h4 class="text-xs font-bold uppercase tracking-wider text-zinc-500">Invoice Line Items</h4>
                    <div class="overflow-x-auto border border-zinc-200 dark:border-zinc-800 rounded-xl">
                        <table class="table-standard w-full min-w-full text-left text-xs">
                            <thead class="bg-zinc-100 dark:bg-zinc-800/80 font-semibold text-zinc-600 dark:text-zinc-300">
                                <tr>
                                    <th class="px-3 py-2">Item Name</th>
                                    <th class="px-3 py-2 w-20">Unit</th>
                                    <th class="px-3 py-2 w-28 text-right">Remaining Unbilled PO</th>
                                    <th class="px-3 py-2 w-28 text-right">Invoiced Qty *</th>
                                    <th class="px-3 py-2 w-36 text-right">Unit Price *</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                                @foreach($invoiceLines as $i => $line)
                                <tr>
                                    <td class="px-3 py-2">
                                        <div class="font-medium text-zinc-800 dark:text-zinc-200">{{ $line['item_name'] }}</div>
                                        <div class="text-[10px] text-zinc-400 uppercase">{{ $line['purchasable_type'] }}</div>
                                    </td>
                                    <td class="px-3 py-2 font-mono uppercase">{{ $line['purchase_uom'] }}</td>
                                    <td class="px-3 py-2 text-right font-medium text-amber-600 tabular-nums">{{ (float) $line['remaining_qty'] }}</td>
                                    <td class="px-3 py-2 text-right">
                                        <input type="number" step="0.0001" min="0.0001" wire:model="invoiceLines.{{ $i }}.invoiced_qty" class="w-full text-xs rounded-lg border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-900 py-1 text-right font-semibold text-zinc-900 dark:text-white tabular-nums" />
                                    </td>
                                    <td class="px-3 py-2 text-right">
                                        <input type="number" step="0.01" min="0" wire:model="invoiceLines.{{ $i }}.unit_price" class="w-full text-xs rounded-lg border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-900 py-1 text-right font-semibold text-zinc-900 dark:text-white tabular-nums" />
                                    </td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
                @elseif($selectedPoId)
                <div class="p-6 text-center text-xs text-zinc-400 border border-dashed rounded-xl">
                    All line items on this PO have been fully invoiced.
                </div>
                @endif

                <div>
                    <x-ui.label for="notes">Invoice Notes</x-ui.label>
                    <textarea id="notes" wire:model="notes" rows="2" placeholder="Tax invoice number, supplier bank account details..." class="mt-1 w-full rounded-xl border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-3 py-2 text-sm"></textarea>
                </div>
            </form>
        </x-slot:content>

        <x-slot:footer>
            <div class="flex items-center justify-end gap-3">
                <x-ui.button type="button" variant="secondary" wire:click="$set('showCreateModal', false)">Cancel</x-ui.button>
                <x-ui.button type="submit" form="createInvoiceForm" variant="primary" wire:loading.attr="disabled">
                    <span wire:loading.remove>Save Draft Invoice</span>
                    <span wire:loading>Saving...</span>
                </x-ui.button>
            </div>
        </x-slot:footer>
    </x-ui.modal>

    <!-- Modal 3-Way Match Workbench -->
    <x-ui.modal wire:model="showMatchModal" maxWidth="4xl">
        <x-slot:title>
            3-Way Match Workbench (PO vs GRN vs Invoice)
        </x-slot:title>

        <x-slot:content>
            <div class="space-y-5">
                <div class="p-4 rounded-xl bg-blue-50/50 dark:bg-blue-950/30 border border-blue-200 dark:border-blue-900 text-xs text-blue-800 dark:text-blue-300">
                    <div class="font-bold flex items-center gap-1.5 mb-1">
                        <span class="material-symbols-rounded text-sm">info</span>
                        Three-Way Matching Policy:
                    </div>
                    The system evaluates PO unit prices, physical goods receipt (GRN) quantities, and vendor invoiced amounts. If discrepancies are within tenant tolerance thresholds, the invoice is set to <strong>MATCHED</strong>.
                </div>

                <div class="overflow-x-auto border border-zinc-200 dark:border-zinc-800 rounded-xl">
                    <table class="table-standard w-full min-w-full text-left text-xs">
                        <thead class="bg-zinc-50 dark:bg-zinc-900/50 font-semibold text-zinc-600 dark:text-zinc-300 border-b border-zinc-200 dark:border-zinc-800">
                            <tr>
                                <th class="px-4 py-2.5">Invoiced Item</th>
                                <th class="px-4 py-2.5 text-right">Invoiced Qty</th>
                                <th class="px-4 py-2.5 text-right">Invoiced Price</th>
                                <th class="px-4 py-2.5 text-center">Associated GRN Receipt</th>
                                <th class="px-4 py-2.5 text-right">Matched Qty</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                            @foreach($matchPlanLines as $i => $m)
                            <tr>
                                <td class="px-4 py-2.5 font-medium text-zinc-900 dark:text-white">
                                    {{ $m['item_name'] }}
                                </td>
                                <td class="px-4 py-2.5 text-right font-semibold tabular-nums">
                                    {{ (float) $m['invoiced_qty'] }}
                                </td>
                                <td class="px-4 py-2.5 text-right font-mono tabular-nums">
                                    Rp {{ number_format((float) $m['unit_price'], 2) }}
                                </td>
                                <td class="px-4 py-2.5 text-center">
                                    <span class="font-mono text-emerald-600 dark:text-emerald-400 font-semibold">GRN Line #{{ $m['goods_received_note_line_id'] ?: 'Auto' }}</span>
                                </td>
                                <td class="px-4 py-2.5 text-right">
                                    <input type="number" step="0.0001" min="0.0001" wire:model="matchPlanLines.{{ $i }}.matched_qty" class="w-24 text-xs rounded-lg border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-900 py-1 text-right font-bold text-emerald-600 tabular-nums" />
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </x-slot:content>

        <x-slot:footer>
            <div class="flex justify-end gap-2">
                <x-ui.button type="button" variant="secondary" wire:click="$set('showMatchModal', false)">Cancel</x-ui.button>
                <x-ui.button type="button" variant="primary" wire:click="executeMatch" icon="compare_arrows">
                    Execute 3-Way Match Evaluation
                </x-ui.button>
            </div>
        </x-slot:footer>
    </x-ui.modal>

    <!-- Modal Persetujuan Varians -->
    <x-ui.modal wire:model="showVarianceModal" maxWidth="md">
        <x-slot:title>
            Invoice Variance Approval
        </x-slot:title>

        <x-slot:content>
            <div class="space-y-3">
                <p class="text-sm text-zinc-600 dark:text-zinc-300">
                    This invoice has a quantity or price variance exceeding tolerance limits. Enter managerial approval justification to release the invoice to payment.
                </p>
                <div>
                    <x-ui.label for="varianceNotes">Approval Justification *</x-ui.label>
                    <x-ui.input type="text" id="varianceNotes" wire:model="varianceNotes" placeholder="e.g. Price increase approved by management per contract addendum" required />
                    <x-input-error :messages="$errors->get('varianceNotes')" class="mt-1" />
                </div>
            </div>
        </x-slot:content>

        <x-slot:footer>
            <div class="flex justify-end gap-2">
                <x-ui.button type="button" variant="secondary" wire:click="$set('showVarianceModal', false)">Cancel</x-ui.button>
                <x-ui.button type="button" variant="primary" wire:click="approveVariance">Approve Variance</x-ui.button>
            </div>
        </x-slot:footer>
    </x-ui.modal>

    <!-- Modal Detail Faktur -->
    <x-ui.modal wire:model="showDetailModal" maxWidth="3xl">
        <x-slot:title>
            @if($selectedInvoice)
                <div>
                    <span class="font-mono">{{ $selectedInvoice->supplier_invoice_number }}</span>
                    <span class="ml-2">
                        <x-ui.badge variant="primary">{{ $selectedInvoice->matching_status?->label() ?? $selectedInvoice->matching_status }}</x-ui.badge>
                    </span>
                </div>
            @else
                Supplier Invoice Details
            @endif
        </x-slot:title>

        <x-slot:content>
            @if($selectedInvoice)
            <div class="space-y-6">
                <!-- Info Header -->
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4 p-4 rounded-xl bg-zinc-50 dark:bg-zinc-900/50 border border-zinc-200 dark:border-zinc-800 text-xs">
                    <div>
                        <div class="text-zinc-400 uppercase font-bold text-[10px]">Supplier</div>
                        <div class="font-semibold text-zinc-800 dark:text-zinc-200 mt-0.5">{{ $selectedInvoice->supplier?->name }}</div>
                        <div class="text-zinc-500 font-mono">{{ $selectedInvoice->supplier?->code }}</div>
                    </div>
                    <div>
                        <div class="text-zinc-400 uppercase font-bold text-[10px]">PO Reference</div>
                        <div class="font-semibold text-zinc-800 dark:text-zinc-200 mt-0.5 font-mono">{{ $selectedInvoice->purchaseOrder?->po_number }}</div>
                    </div>
                    <div>
                        <div class="text-zinc-400 uppercase font-bold text-[10px]">Invoice Date</div>
                        <div class="font-semibold text-zinc-800 dark:text-zinc-200 mt-0.5">{{ \Carbon\Carbon::parse($selectedInvoice->invoice_date)->format('d M Y') }}</div>
                    </div>
                    <div>
                        <div class="text-zinc-400 uppercase font-bold text-[10px]">Due Date</div>
                        <div class="font-semibold text-zinc-800 dark:text-zinc-200 mt-0.5">{{ \Carbon\Carbon::parse($selectedInvoice->due_date)->format('d M Y') }}</div>
                    </div>
                </div>

                <!-- Lines Table -->
                <div class="space-y-2">
                    <h4 class="text-xs font-bold uppercase tracking-wider text-zinc-500">Invoiced Lines & GRN Matching</h4>
                    <div class="overflow-x-auto border border-zinc-200 dark:border-zinc-800 rounded-xl">
                        <table class="table-standard w-full min-w-full text-left text-xs">
                            <thead class="bg-zinc-50 dark:bg-zinc-900/50 font-semibold text-zinc-500 border-b border-zinc-200 dark:border-zinc-800">
                                <tr>
                                    <th class="px-4 py-2.5">Item</th>
                                    <th class="px-4 py-2.5 text-right">Quantity</th>
                                    <th class="px-4 py-2.5 text-right">Unit Price</th>
                                    <th class="px-4 py-2.5 text-right">Subtotal</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                                @foreach($selectedInvoice->lines as $line)
                                <tr>
                                    <td class="px-4 py-2.5 font-medium text-zinc-800 dark:text-zinc-200">
                                        {{ $line->purchasable?->product_name ?? $line->purchasable?->name ?? 'Item #' . $line->purchasable_id }}
                                    </td>
                                    <td class="px-4 py-2.5 text-right font-medium tabular-nums">{{ (float) $line->invoiced_quantity }}</td>
                                    <td class="px-4 py-2.5 text-right tabular-nums">Rp {{ number_format((float) $line->unit_price, 2) }}</td>
                                    <td class="px-4 py-2.5 text-right font-semibold tabular-nums">Rp {{ number_format((float) $line->subtotal_amount, 2) }}</td>
                                </tr>
                                @endforeach
                            </tbody>
                            <tfoot class="bg-zinc-50 dark:bg-zinc-900/50 font-semibold border-t border-zinc-200 dark:border-zinc-800">
                                <tr>
                                    <td colspan="3" class="px-4 py-2 text-right text-zinc-500">Total Invoiced Amount:</td>
                                    <td class="px-4 py-2 text-right text-zinc-900 dark:text-white font-bold text-sm tabular-nums">
                                        Rp {{ number_format((float) $selectedInvoice->total_amount, 2) }}
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>

                @if($selectedInvoice->matching_status?->value === 'VARIANCE_HELD' || $selectedInvoice->matching_status === 'variance_held')
                <div class="p-4 rounded-xl bg-amber-50 dark:bg-amber-950/40 border border-amber-200 dark:border-amber-900 flex items-center justify-between">
                    <div>
                        <div class="text-xs font-bold text-amber-800 dark:text-amber-300">Invoice Held for Variance</div>
                        <div class="text-[11px] text-amber-700 dark:text-amber-400 mt-0.5">A price or quantity variance was detected that requires managerial approval.</div>
                    </div>
                    <x-ui.button type="button" variant="warning" size="sm" wire:click="confirmApproveVariance">
                        Approve Variance
                    </x-ui.button>
                </div>
                @endif
            </div>
            @endif
        </x-slot:content>

        <x-slot:footer>
            <div class="flex items-center justify-between">
                <div>
                    @if($selectedInvoice && ($selectedInvoice->matching_status?->value === 'MATCHED' || $selectedInvoice->matching_status === 'matched') && ! $selectedInvoice->isApprovedPayable())
                        <x-ui.button type="button" variant="primary" wire:click="approvePayable({{ $selectedInvoice->id }})">
                            Approve Payment & Post AP (General Ledger)
                        </x-ui.button>
                    @endif
                </div>
                <div class="flex items-center gap-2">
                    @if($selectedInvoice && ! $selectedInvoice->isApprovedPayable() && ! $selectedInvoice->isCancelled())
                        <x-ui.button type="button" variant="secondary" wire:click="openMatchWorkbench({{ $selectedInvoice->id }})">
                            3-Way Match Workbench
                        </x-ui.button>
                    @endif
                    <x-ui.button type="button" variant="secondary" wire:click="$set('showDetailModal', false)">Close</x-ui.button>
                </div>
            </div>
        </x-slot:footer>
    </x-ui.modal>
</div>
