<div class="space-y-6" data-gsap="page-enter">
    @include('sales.partials.sub-nav')

    <!-- Header -->
    <x-erp.page-header
        title="Sales Returns & Credit Notes"
        description="Manage item returns, customer credit notes, and receivable reconciliation.">
        <x-slot:breadcrumbs>
            <x-ui.breadcrumbs.item href="{{ route('sales.orders.index') }}">Dashboard</x-ui.breadcrumbs.item>
            <x-ui.breadcrumbs.item href="{{ route('sales.orders.index') }}">Sales</x-ui.breadcrumbs.item>
            <x-ui.breadcrumbs.item active>Returns & Credit Notes</x-ui.breadcrumbs.item>
        </x-slot:breadcrumbs>

        <x-slot:actions>
            <x-ui.button variant="secondary" href="{{ route('sales.invoices.index') }}" class="gap-2">
                <span class="material-symbols-rounded text-base">receipt_long</span>
                Accounts Receivable (AR)
            </x-ui.button>
        </x-slot:actions>
    </x-erp.page-header>

    <!-- Alert Messages -->
    @if ($errorMessage)
        <x-ui.alert type="danger" title="An Error Occurred" class="rounded-2xl">
            {{ $errorMessage }}
        </x-ui.alert>
    @endif
    @if ($successMessage)
        <x-ui.alert type="success" title="Success" class="rounded-2xl">
            {{ $successMessage }}
        </x-ui.alert>
    @endif

    <!-- Tabs Navigation -->
    <div class="flex items-center border-b border-border gap-6">
        <button wire:click="switchTab('credit_notes')"
            class="pb-3 text-sm font-semibold border-b-2 transition flex items-center gap-2 {{ $activeTab === 'credit_notes' ? 'border-primary text-primary ' : 'border-transparent text-muted-foreground hover:text-foreground ' }}">
            <span class="material-symbols-rounded text-lg">credit_card</span>
            Sales Credit Notes
        </button>
        <button wire:click="switchTab('returns')"
            class="pb-3 text-sm font-semibold border-b-2 transition flex items-center gap-2 {{ $activeTab === 'returns' ? 'border-primary text-primary ' : 'border-transparent text-muted-foreground hover:text-foreground ' }}">
            <span class="material-symbols-rounded text-lg">assignment_return</span>
            Physical Order Returns
        </button>
    </div>

    <!-- Filters & Search -->
    <div class="card-surface p-4 rounded-2xl flex flex-col sm:flex-row gap-4 justify-between items-center border border-border">
        <div class="relative w-full sm:w-80">
            <span class="material-symbols-rounded absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground text-lg">search</span>
            <input type="text" wire:model.live.debounce.300ms="search" placeholder="Search document number / customer..."
                class="w-full pl-10 pr-4 py-2 text-sm rounded-xl border border-border bg-card focus:outline-hidden focus:ring-2 focus:ring-ring/20">
        </div>
        <div class="w-full sm:w-auto flex items-center gap-3">
            @if ($activeTab === 'credit_notes')
                <x-ui.select wire:model.live="statusFilter" class="w-auto text-sm">
                    <option value="">All Statuses</option>
                    @foreach ($statuses as $st)
                        <option value="{{ $st->value }}">{{ $st->label() }}</option>
                    @endforeach
                </x-ui.select>
            @endif
        </div>
    </div>

    <!-- Content: Credit Notes -->
    @if ($activeTab === 'credit_notes')
        <div class="card-surface rounded-2xl border border-border overflow-hidden shadow-xs">
            <div class="overflow-x-auto">
                <table class="table-standard min-w-full text-left border-collapse text-sm">
                    <thead class="border-b border-border bg-secondary/50 /50 text-xs font-semibold text-muted-foreground uppercase tracking-wider">
                        <tr>
                            <th scope="col" class="px-6 py-3.5">Credit Note No.</th>
                            <th scope="col" class="px-6 py-3.5">Customer</th>
                            <th scope="col" class="px-6 py-3.5">Date</th>
                            <th scope="col" class="px-6 py-3.5 text-right">Total Amount</th>
                            <th scope="col" class="px-6 py-3.5 text-right">Applied</th>
                            <th scope="col" class="px-6 py-3.5 text-right">Remaining Balance</th>
                            <th scope="col" class="px-6 py-3.5">Status</th>
                            <th scope="col" class="px-6 py-3.5 text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @forelse ($creditNotes as $cn)
                            <tr class="hover:bg-secondary/50 transition">
                                <td class="px-6 py-4 font-sans font-medium text-foreground">
                                    {{ $cn->credit_note_number }}
                                </td>
                                <td class="px-6 py-4">
                                    <div class="font-medium text-foreground">{{ $cn->customer?->name ?? 'Walk-in / N/A' }}</div>
                                    <div class="text-xs text-muted-foreground">{{ $cn->customer?->company_name }}</div>
                                </td>
                                <td class="px-6 py-4 text-xs text-muted-foreground">
                                    {{ $cn->issue_date?->format('d/m/Y') }}
                                </td>
                                <td class="px-6 py-4 text-right font-medium text-foreground tabular-nums">
                                    Rp {{ number_format((float) $cn->total_amount, 2, ',', '.') }}
                                </td>
                                <td class="px-6 py-4 text-right text-muted-foreground tabular-nums">
                                    Rp {{ number_format((float) $cn->applied_amount, 2, ',', '.') }}
                                </td>
                                <td class="px-6 py-4 text-right font-semibold text-positive tabular-nums">
                                    Rp {{ number_format((float) $cn->remaining_amount, 2, ',', '.') }}
                                </td>
                                <td class="px-6 py-4">
                                    @php
                                        $dotColor = match($cn->status->value) {
                                            'APPLIED' => 'bg-success',
                                            'PARTIALLY_APPLIED' => 'bg-info',
                                            'CONVERTED_TO_STORE_CREDIT' => 'bg-neutral',
                                            'VOIDED' => 'bg-destructive',
                                            default => 'bg-warning',
                                        };
                                    @endphp
                                    <span class="inline-flex items-center gap-1.5 text-xs text-foreground font-medium">
                                        <span class="w-1.5 h-1.5 rounded-full {{ $dotColor }}"></span>
                                        {{ $cn->status->label() }}
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-right whitespace-nowrap">
                                    @if ((float) $cn->remaining_amount > 0 && in_array($cn->status->value, ['ISSUED', 'PARTIALLY_APPLIED']))
                                        <x-ui.table-actions>
                                            <x-ui.table-action-item icon="receipt_long" wire:click="openApplyModal({{ $cn->id }})" aria-label="Apply to Invoice">
                                                Apply to Invoice
                                            </x-ui.table-action-item>
                                            <x-ui.table-action-item icon="account_balance_wallet" wire:click="convertToStoreCredit({{ $cn->id }})" aria-label="Convert to Store Credit">
                                                Convert to Store Credit
                                            </x-ui.table-action-item>
                                        </x-ui.table-actions>
                                    @else
                                        <span class="text-xs text-muted-foreground">-</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="px-6 py-8 text-center text-muted-foreground text-sm">
                                    No credit notes found.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($creditNotes->hasPages())
                <div class="p-4 border-t border-border">
                    {{ $creditNotes->links() }}
                </div>
            @endif
        </div>
    @endif

    <!-- Content: Physical Returns -->
    @if ($activeTab === 'returns')
        <div class="card-surface rounded-2xl border border-border overflow-hidden shadow-xs">
            <div class="overflow-x-auto">
                <table class="table-standard min-w-full text-left border-collapse text-sm">
                    <thead class="border-b border-border bg-secondary/50 /50 text-xs font-semibold text-muted-foreground uppercase tracking-wider">
                        <tr>
                            <th scope="col" class="px-6 py-3.5">Return No.</th>
                            <th scope="col" class="px-6 py-3.5">Source Order</th>
                            <th scope="col" class="px-6 py-3.5">Customer</th>
                            <th scope="col" class="px-6 py-3.5">Type</th>
                            <th scope="col" class="px-6 py-3.5 text-right">Goods Value</th>
                            <th scope="col" class="px-6 py-3.5 text-right">Total Refund / CN</th>
                            <th scope="col" class="px-6 py-3.5">Status</th>
                            <th scope="col" class="px-6 py-3.5">Completed At</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @forelse ($returns as $ret)
                            <tr class="hover:bg-secondary/50 transition">
                                <td class="px-6 py-4 font-sans font-medium text-foreground">
                                    {{ $ret->return_number }}
                                </td>
                                <td class="px-6 py-4 font-sans text-xs text-muted-foreground">
                                    {{ $ret->order?->order_code ?? '-' }}
                                </td>
                                <td class="px-6 py-4">
                                    <div class="font-medium text-foreground">{{ $ret->customer?->name ?? 'Walk-in' }}</div>
                                </td>
                                <td class="px-6 py-4 text-xs capitalize text-muted-foreground">
                                    {{ $ret->return_type }}
                                </td>
                                <td class="px-6 py-4 text-right text-foreground tabular-nums">
                                    Rp {{ number_format((float) $ret->subtotal_returned, 2, ',', '.') }}
                                </td>
                                <td class="px-6 py-4 text-right font-medium text-foreground tabular-nums">
                                    Rp {{ number_format((float) $ret->total_refundable_amount, 2, ',', '.') }}
                                </td>
                                <td class="px-6 py-4">
                                    <span class="inline-flex items-center gap-1.5 text-xs text-foreground font-medium">
                                        <span class="w-1.5 h-1.5 rounded-full bg-success"></span>
                                        {{ $ret->status }}
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-xs text-muted-foreground">
                                    {{ $ret->completed_at?->format('d/m/Y H:i') ?? '-' }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="px-6 py-8 text-center text-muted-foreground text-sm">
                                    No physical return records found.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($returns->hasPages())
                <div class="p-4 border-t border-border">
                    {{ $returns->links() }}
                </div>
            @endif
        </div>
    @endif

    <!-- Apply Credit Note Modal -->
    <x-ui.modal wire:model="showApplyModal" maxWidth="lg">
        <x-slot:title>
            Apply Credit Note to Invoice
        </x-slot:title>

        <x-slot:content>
            <div class="space-y-4">
                <p class="text-xs text-muted-foreground">Select the receivable invoice to be credited by this credit note.</p>

                <div class="space-y-3">
                    <div>
                        <x-ui.label for="targetInvoiceId">Target Invoice *</x-ui.label>
                        <x-ui.select id="targetInvoiceId" wire:model.live="targetInvoiceId">
                            <option value="">-- Select Unpaid Invoice --</option>
                            @foreach ($eligibleInvoices as $inv)
                                <option value="{{ $inv->id }}">
                                    {{ $inv->invoice_number }} (Due: {{ $inv->due_date?->format('d/m/Y') }} | Remaining: Rp {{ number_format((float) $inv->balance_due, 2, ',', '.') }})
                                </option>
                            @endforeach
                        </x-ui.select>
                        @error('targetInvoiceId') <span class="text-xs text-destructive mt-1 block">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <x-ui.label for="applyAmount">Amount to Apply *</x-ui.label>
                        <x-ui.input type="number" step="0.01" id="applyAmount" wire:model="applyAmount" />
                        @error('applyAmount') <span class="text-xs text-destructive mt-1 block">{{ $message }}</span> @enderror
                    </div>
                </div>
            </div>
        </x-slot:content>

        <x-slot:footer>
            <div class="flex justify-end gap-3">
                <x-ui.button variant="secondary" wire:click="closeApplyModal">
                    Cancel
                </x-ui.button>
                <x-ui.button variant="primary" wire:click="applyCreditNote">
                    Confirm Application
                </x-ui.button>
            </div>
        </x-slot:footer>
    </x-ui.modal>
</div>
