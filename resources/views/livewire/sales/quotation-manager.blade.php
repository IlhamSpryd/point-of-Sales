<div class="space-y-6" data-gsap="page-enter">
    @include('sales.partials.sub-nav')

    <!-- Header -->
    <x-erp.page-header
        title="Price Quotations"
        description="Manage B2B commercial quotations, discount/margin approvals, validity dates, and one-click conversion to Sales Orders.">
        <x-slot:breadcrumbs>
            <x-ui.breadcrumbs.item href="{{ route('sales.orders.index') }}">Dashboard</x-ui.breadcrumbs.item>
            <x-ui.breadcrumbs.item href="{{ route('sales.orders.index') }}">Sales</x-ui.breadcrumbs.item>
            <x-ui.breadcrumbs.item active>Price Quotations</x-ui.breadcrumbs.item>
        </x-slot:breadcrumbs>

        <x-slot:actions>
            @can('create', \App\Modules\Sales\Models\Quotation::class)
            <x-ui.button variant="primary" wire:click="openCreateModal" class="gap-2">
                <span class="material-symbols-rounded text-base">add_circle</span>
                Create New Quotation
            </x-ui.button>
            @endcan
        </x-slot:actions>
    </x-erp.page-header>

    <!-- Flash Messages -->
    @if (session()->has('message'))
        <x-ui.alert type="success" title="Success" class="rounded-2xl">
            {{ session('message') }}
        </x-ui.alert>
    @endif

    @if (session()->has('error'))
        <x-ui.alert type="danger" title="An Error Occurred" class="rounded-2xl">
            {{ session('error') }}
        </x-ui.alert>
    @endif

    <!-- Filter & Toolbar -->
    <div class="card-surface p-4 rounded-2xl flex flex-col md:flex-row md:items-center justify-between gap-4 border border-border">
        <div class="flex-1 max-w-md">
            <div class="relative">
                <span class="material-symbols-rounded absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground text-lg">search</span>
                <input type="text" wire:model.live.debounce.300ms="search" placeholder="Search quotation number, customer name, code..." class="w-full pl-10 pr-4 py-2 text-sm rounded-xl border border-border bg-card focus:outline-hidden focus:ring-2 focus:ring-ring/20 text-foreground">
            </div>
        </div>
        <div class="flex flex-wrap items-center gap-3">
            <x-ui.select wire:model.live="statusFilter" class="w-auto text-sm">
                <option value="">All Statuses</option>
                <option value="DRAFT">Draft</option>
                <option value="PENDING_APPROVAL">Pending Approval</option>
                <option value="APPROVED">Approved</option>
                <option value="SENT">Sent</option>
                <option value="ACCEPTED">Accepted</option>
                <option value="CONVERTED">Converted (SO)</option>
                <option value="REJECTED">Rejected</option>
                <option value="EXPIRED">Expired</option>
                <option value="SUPERSEDED">Superseded</option>
            </x-ui.select>
        </div>
    </div>

    <!-- Table -->
    <div class="card-surface overflow-hidden rounded-2xl border border-border shadow-xs">
        <div class="overflow-x-auto">
            <table class="table-standard min-w-full text-left border-collapse text-sm">
                <thead>
                    <tr class="border-b border-border bg-secondary/50 /50 text-xs font-semibold text-muted-foreground uppercase tracking-wider">
                        <th scope="col" class="py-3.5 px-6">Quotation No.</th>
                        <th scope="col" class="py-3.5 px-6">Customer</th>
                        <th scope="col" class="py-3.5 px-6">Date / Validity</th>
                        <th scope="col" class="py-3.5 px-6 text-right">Total Value</th>
                        <th scope="col" class="py-3.5 px-6">Status</th>
                        <th scope="col" class="py-3.5 px-6 text-right">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse($quotations as $qtn)
                        <tr class="hover:bg-secondary/60 transition">
                            <td class="py-4 px-6">
                                <div class="font-medium text-foreground flex items-center gap-1.5 font-sans">
                                    <span>{{ $qtn->quotation_number }}</span>
                                    <span class="text-xs text-muted-foreground font-medium font-sans">v{{ $qtn->version_number }}</span>
                                </div>
                                <div class="text-xs text-muted-foreground mt-0.5">Branch: {{ $qtn->store?->name ?? 'Headquarters' }}</div>
                            </td>
                            <td class="py-4 px-6">
                                <div class="font-medium text-foreground">{{ $qtn->customer?->name }}</div>
                                <div class="text-xs text-muted-foreground font-sans">{{ $qtn->customer?->customer_code }}</div>
                            </td>
                            <td class="py-4 px-6">
                                <div class="text-foreground">{{ $qtn->issue_date->format('d/m/Y') }}</div>
                                <div class="text-xs text-muted-foreground">to {{ $qtn->valid_until->format('d/m/Y') }}</div>
                            </td>
                            <td class="py-4 px-6 text-right font-medium text-foreground tabular-nums">
                                Rp {{ number_format((float) $qtn->total_amount, 2, ',', '.') }}
                            </td>
                            <td class="py-4 px-6">
                                @php
                                    $statusDot = match($qtn->status->value) {
                                        'DRAFT' => 'bg-neutral',
                                        'PENDING_APPROVAL' => 'bg-warning',
                                        'APPROVED', 'SENT' => 'bg-info',
                                        'ACCEPTED' => 'bg-success',
                                        'CONVERTED', 'SUPERSEDED' => 'bg-neutral',
                                        'REJECTED' => 'bg-destructive',
                                        'EXPIRED' => 'bg-warning',
                                        default => 'bg-neutral',
                                    };
                                @endphp
                                <span class="inline-flex items-center gap-1.5 text-xs text-foreground font-medium">
                                    <span class="w-1.5 h-1.5 rounded-full {{ $statusDot }}"></span>
                                    {{ $qtn->status->value }}
                                </span>
                            </td>
                            <td class="py-4 px-6 text-right whitespace-nowrap">
                                <x-ui.table-actions>
                                    <x-ui.table-action-item icon="visibility" wire:click="viewQuotation({{ $qtn->id }})" aria-label="View Quotation Details">
                                        View Details
                                    </x-ui.table-action-item>
                                    @if($qtn->status->value === 'ACCEPTED' && $qtn->converted_sales_order_id === null)
                                        @can('convert', $qtn)
                                        <x-ui.table-action-item icon="shopping_bag" wire:click="convertToSalesOrder({{ $qtn->id }})" aria-label="Convert to Sales Order">
                                            Convert to Sales Order
                                        </x-ui.table-action-item>
                                        @endcan
                                    @endif
                                </x-ui.table-actions>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="py-12 text-center text-muted-foreground">
                                <span class="material-symbols-rounded text-4xl block mb-2">request_quote</span>
                                No price quotation data available.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($quotations->hasPages())
            <div class="p-4 border-t border-border">
                {{ $quotations->links() }}
            </div>
        @endif
    </div>

    <!-- Modal 1: Create Quotation -->
    <x-ui.modal wire:model="showCreateModal" maxWidth="4xl">
        <x-slot:title>
            Create New Price Quotation
        </x-slot:title>

        <x-slot:content>
            <x-erp.dirty-guard watch="lines" />
            <div class="space-y-5">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <x-erp.form-field label="Customer" required :error="$errors->first('customer_id')">
                            <x-erp.searchable-select
                                id="quotation_customer_id"
                                wire:model="customer_id"
                                :options="collect($customers)->map(fn($c) => ['value' => (string) $c->id, 'label' => $c->name . ' (' . $c->customer_code . ')' . ($c->company_name ? ' - ' . $c->company_name : '')])->all()"
                                placeholder="-- Select Customer --"
                            />
                        </x-erp.form-field>
                    </div>

                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <x-ui.label for="issue_date">Quotation Date *</x-ui.label>
                            <x-ui.input type="date" id="issue_date" wire:model="issue_date" />
                        </div>
                        <div>
                            <x-ui.label for="valid_until">Valid Until *</x-ui.label>
                            <x-ui.input type="date" id="valid_until" wire:model="valid_until" />
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div>
                        <x-ui.label for="payment_terms_days">Payment Terms (Days)</x-ui.label>
                        <x-ui.input type="number" id="payment_terms_days" wire:model="payment_terms_days" min="0" placeholder="0 = Cash / Immediate" />
                    </div>
                    <div class="sm:col-span-2">
                        <x-ui.label for="notes">Additional Notes</x-ui.label>
                        <x-ui.input type="text" id="notes" wire:model="notes" placeholder="Internal or shipping notes..." />
                    </div>
                </div>

                <!-- Lines Table -->
                <div class="border border-border rounded-xl overflow-hidden">
                    <div class="bg-secondary /60 px-4 py-2.5 border-b border-border flex items-center justify-between">
                        <span class="text-xs font-bold text-foreground uppercase tracking-wider">Quotation Items</span>
                        <x-ui.button type="button" variant="secondary" size="xs" wire:click="addLine" class="gap-1">
                            <span class="material-symbols-rounded text-sm">add</span> Add Line
                        </x-ui.button>
                    </div>

                    <div class="p-3 space-y-3">
                        @foreach($lines as $idx => $line)
                            <div wire:key="line-{{ $line->id ?? ($idx ?? $loop->index) }}" class="flex flex-col sm:flex-row items-center gap-2 p-2 rounded-lg bg-secondary/50 /30 border border-border /50">
                                <div class="flex-1 w-full sm:w-auto">
                                    <x-ui.select wire:model="lines.{{ $idx }}.product_id" class="text-xs">
                                        <option value="">-- Select Product --</option>
                                        @foreach($products as $p)
                                            <option value="{{ $p->id }}">{{ $p->product_name ?? $p->name }} ({{ $p->product_code ?? $p->sku }})</option>
                                        @endforeach
                                    </x-ui.select>
                                </div>
                                <div class="w-24">
                                    <x-ui.input type="number" step="0.0001" wire:model="lines.{{ $idx }}.quantity" placeholder="Qty" class="text-right text-xs" />
                                </div>
                                <div class="w-28">
                                    <x-ui.input type="number" step="0.01" wire:model="lines.{{ $idx }}.unit_price" placeholder="Price" class="text-right text-xs" />
                                </div>
                                <div class="w-24">
                                    <x-ui.input type="number" step="0.01" wire:model="lines.{{ $idx }}.discount_amount" placeholder="Discount" class="text-right text-xs" />
                                </div>
                                <div class="w-20">
                                    <x-ui.input type="number" step="0.01" wire:model="lines.{{ $idx }}.tax_rate" placeholder="Tax %" class="text-right text-xs" />
                                </div>
                                <div>
                                    <button type="button" wire:click="removeLine({{ $idx }})" class="p-1 text-muted-foreground hover:text-destructive rounded">
                                        <span class="material-symbols-rounded text-lg">delete</span>
                                    </button>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </x-slot:content>

        <x-slot:footer>
            <div class="flex justify-end gap-3">
                <x-ui.button variant="secondary" wire:click="closeCreateModal">
                    Cancel
                </x-ui.button>
                <x-ui.button variant="primary" wire:click="createQuotation" wire:loading.attr="disabled">
                    Save Quotation
                </x-ui.button>
            </div>
        </x-slot:footer>
    </x-ui.modal>

    <!-- Modal 2: Detail Quotation -->
    <x-ui.modal wire:model="showDetailModal" maxWidth="4xl">
        <x-slot:title>
            @if($selectedQuotation)
                <div class="flex items-center gap-2">
                    <span>{{ $selectedQuotation->quotation_number }}</span>
                    <span class="text-xs text-muted-foreground font-medium font-sans">(v{{ $selectedQuotation->version_number }})</span>
                </div>
            @else
                Price Quotation Details
            @endif
        </x-slot:title>

        <x-slot:content>
            @if($selectedQuotation)
            <div class="space-y-6">
                <div class="text-xs text-muted-foreground -mt-2">
                    Created by: {{ $selectedQuotation->createdBy?->name }} on {{ $selectedQuotation->created_at->format('d/m/Y H:i') }}
                </div>

                <!-- Status & Action Bar -->
                <div class="p-4 rounded-xl bg-secondary /40 border border-border/80 flex flex-wrap items-center justify-between gap-3">
                    <div class="flex items-center gap-2">
                        <span class="text-xs font-semibold text-muted-foreground uppercase">Status:</span>
                        <span class="font-bold text-foreground">{{ $selectedQuotation->status->value }}</span>
                        @if($selectedQuotation->requires_approval)
                            <span class="inline-flex items-center gap-1.5 text-xs text-warning font-medium">
                                <span class="w-1.5 h-1.5 rounded-full bg-warning"></span>
                                Requires Discount Approval
                            </span>
                        @endif
                    </div>

                    <div class="flex items-center gap-2">
                        @if($selectedQuotation->status->value === 'DRAFT')
                            <x-ui.button variant="primary" size="sm" wire:click="submitForApprovalOrSend({{ $selectedQuotation->id }})">
                                Submit / Send to Customer
                            </x-ui.button>
                        @endif

                        @if($selectedQuotation->status->value === 'PENDING_APPROVAL')
                            @can('approve', $selectedQuotation)
                            <x-ui.button variant="success" size="sm" wire:click="approve({{ $selectedQuotation->id }})">
                                Approve
                            </x-ui.button>
                            <x-ui.button variant="danger" size="sm" wire:click="openRejectModal({{ $selectedQuotation->id }})">
                                Reject
                            </x-ui.button>
                            @endcan
                        @endif

                        @if(in_array($selectedQuotation->status->value, ['APPROVED', 'SENT']))
                            <x-ui.button variant="success" size="sm" wire:click="accept({{ $selectedQuotation->id }})">
                                Customer Accepted
                            </x-ui.button>
                        @endif

                        @if($selectedQuotation->status->value === 'ACCEPTED' && $selectedQuotation->converted_sales_order_id === null)
                            @can('convert', $selectedQuotation)
                            <x-ui.button variant="primary" size="sm" wire:click="convertToSalesOrder({{ $selectedQuotation->id }})" class="gap-1.5 shadow-xs">
                                <span class="material-symbols-rounded text-sm">shopping_bag</span>
                                Convert to Sales Order (1x)
                            </x-ui.button>
                            @endcan
                        @endif

                        @if($selectedQuotation->convertedSalesOrder)
                            <div class="text-xs font-medium text-primary bg-secondary px-3 py-1.5 rounded-lg border border-border">
                                Linked to SO: {{ $selectedQuotation->convertedSalesOrder->so_number }}
                            </div>
                        @endif
                    </div>
                </div>

                <!-- Info Grid -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
                    <div class="p-4 rounded-xl border border-border">
                        <h4 class="font-bold text-foreground mb-2 text-xs uppercase tracking-wider">Customer Information</h4>
                        <div class="space-y-1 text-xs">
                            <p><span class="text-muted-foreground">Name:</span> <strong class="text-foreground">{{ $selectedQuotation->customer?->name }}</strong></p>
                            <p><span class="text-muted-foreground">Code:</span> <span class="font-sans">{{ $selectedQuotation->customer?->customer_code }}</span></p>
                            <p><span class="text-muted-foreground">Phone:</span> {{ $selectedQuotation->customer?->phone ?? '-' }}</p>
                            <p><span class="text-muted-foreground">Email:</span> {{ $selectedQuotation->customer?->email ?? '-' }}</p>
                        </div>
                    </div>
                    <div class="p-4 rounded-xl border border-border">
                        <h4 class="font-bold text-foreground mb-2 text-xs uppercase tracking-wider">Commercial Terms</h4>
                        <div class="space-y-1 text-xs">
                            <p><span class="text-muted-foreground">Quotation Date:</span> {{ $selectedQuotation->issue_date->format('d/m/Y') }}</p>
                            <p><span class="text-muted-foreground">Valid Until:</span> {{ $selectedQuotation->valid_until->format('d/m/Y') }}</p>
                            <p><span class="text-muted-foreground">Payment Terms:</span> {{ $selectedQuotation->payment_terms_days }} Days</p>
                            <p><span class="text-muted-foreground">Currency:</span> {{ $selectedQuotation->currency_code }}</p>
                        </div>
                    </div>
                </div>

                <!-- Lines -->
                <div class="border border-border rounded-xl overflow-x-auto">
                    <table class="table-standard min-w-full text-left text-xs">
                        <thead class="bg-secondary text-muted-foreground uppercase font-semibold">
                            <tr>
                                <th scope="col" class="p-3">#</th>
                                <th scope="col" class="p-3">Product</th>
                                <th scope="col" class="p-3 text-right">Quantity</th>
                                <th scope="col" class="p-3 text-right">Unit Price</th>
                                <th scope="col" class="p-3 text-right">Discount</th>
                                <th scope="col" class="p-3 text-right">Tax</th>
                                <th scope="col" class="p-3 text-right">Total</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y border-border">
                            @foreach($selectedQuotation->lines as $line)
                                <tr wire:key="line-{{ $line->id ?? $loop->index }}">
                                    <td class="p-3 font-sans text-muted-foreground">{{ $line->line_number }}</td>
                                    <td class="p-3">
                                        <div class="font-medium text-foreground">{{ $line->description ?: ($line->product?->product_name ?? $line->product?->name) }}</div>
                                        <div class="text-[10px] text-muted-foreground font-sans">{{ $line->product?->product_code ?? $line->product?->sku }} | Source: {{ $line->pricing_source }}</div>
                                    </td>
                                    <td class="p-3 text-right font-sans tabular-nums">{{ number_format((float) $line->quantity, 2) }}</td>
                                    <td class="p-3 text-right font-sans tabular-nums">Rp {{ number_format((float) $line->unit_price, 2, ',', '.') }}</td>
                                    <td class="p-3 text-right font-sans text-destructive tabular-nums">-Rp {{ number_format((float) $line->discount_amount, 2, ',', '.') }}</td>
                                    <td class="p-3 text-right font-sans tabular-nums">Rp {{ number_format((float) $line->tax_amount, 2, ',', '.') }}</td>
                                    <td class="p-3 text-right font-sans font-bold text-foreground tabular-nums">Rp {{ number_format((float) $line->line_total, 2, ',', '.') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot class="bg-secondary/50 /50 border-t border-border font-semibold text-xs">
                            <tr>
                                <td colspan="6" class="p-2.5 text-right text-muted-foreground">Subtotal:</td>
                                <td class="p-2.5 text-right font-sans tabular-nums">Rp {{ number_format((float) $selectedQuotation->subtotal, 2, ',', '.') }}</td>
                            </tr>
                            <tr>
                                <td colspan="6" class="p-2.5 text-right text-muted-foreground">Total Discount:</td>
                                <td class="p-2.5 text-right font-sans text-destructive tabular-nums">-Rp {{ number_format((float) $selectedQuotation->discount_amount, 2, ',', '.') }}</td>
                            </tr>
                            <tr>
                                <td colspan="6" class="p-2.5 text-right text-muted-foreground">Total Tax:</td>
                                <td class="p-2.5 text-right font-sans tabular-nums">Rp {{ number_format((float) $selectedQuotation->tax_amount, 2, ',', '.') }}</td>
                            </tr>
                            <tr class="text-sm font-bold border-t border-border">
                                <td colspan="6" class="p-3 text-right text-foreground">TOTAL QUOTATION:</td>
                                <td class="p-3 text-right font-sans text-positive tabular-nums">Rp {{ number_format((float) $selectedQuotation->total_amount, 2, ',', '.') }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
            @endif
        </x-slot:content>

        <x-slot:footer>
            <div class="flex justify-end">
                <x-ui.button variant="secondary" wire:click="closeDetailModal">
                    Close
                </x-ui.button>
            </div>
        </x-slot:footer>
    </x-ui.modal>

    <!-- Modal 3: Reject -->
    <x-ui.modal wire:model="showRejectModal" maxWidth="md">
        <x-slot:title>
            Reject Price Quotation
        </x-slot:title>

        <x-slot:content>
            <div class="space-y-4">
                <div>
                    <x-ui.label for="rejectReason">Rejection Reason *</x-ui.label>
                    <textarea id="rejectReason" wire:model="rejectReason" rows="3" placeholder="Enter reason for rejecting this quotation..." class="w-full text-sm rounded-xl border border-border bg-card p-2.5 text-foreground"></textarea>
                    @error('rejectReason') <span class="text-xs text-destructive mt-1 block">{{ $message }}</span> @enderror
                </div>
            </div>
        </x-slot:content>

        <x-slot:footer>
            <div class="flex justify-end gap-3">
                <x-ui.button variant="secondary" wire:click="$set('showRejectModal', false)">
                    Cancel
                </x-ui.button>
                <x-ui.button variant="danger" wire:click="reject">
                    Confirm Rejection
                </x-ui.button>
            </div>
        </x-slot:footer>
    </x-ui.modal>
</div>