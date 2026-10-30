<div class="space-y-6" data-gsap="page-enter">
    @include('sales.partials.sub-nav')

    <!-- Header -->
    <x-erp.page-header
        title="Sales Orders"
        description="Manage B2B commercial orders, stock reservations, partial fulfillments, and repeat orders.">
        <x-slot:breadcrumbs>
            <x-ui.breadcrumbs.item href="{{ route('dashboard') }}">Dashboard</x-ui.breadcrumbs.item>
            <x-ui.breadcrumbs.item href="{{ route('sales.orders.index') }}">Sales</x-ui.breadcrumbs.item>
            <x-ui.breadcrumbs.item active>Sales Orders</x-ui.breadcrumbs.item>
        </x-slot:breadcrumbs>

        <x-slot:actions>
            <x-ui.button variant="secondary" href="{{ route('sales.quotations.index') }}" class="gap-2">
                <span class="material-symbols-rounded text-base">request_quote</span>
                Quotations List
            </x-ui.button>
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
    <div class="card-surface p-4 rounded-2xl flex flex-col md:flex-row md:items-center justify-between gap-4 border border-[#E5E7EB] dark:border-zinc-800">
        <div class="flex-1 max-w-md">
            <div class="relative">
                <span class="material-symbols-rounded absolute left-3 top-1/2 -translate-y-1/2 text-zinc-400 text-lg">search</span>
                <input type="text" wire:model.live.debounce.300ms="search" placeholder="Search SO No., Customer PO, name..." class="w-full pl-10 pr-4 py-2 text-sm rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 focus:outline-hidden focus:ring-2 focus:ring-apeiron-primary/20 text-zinc-800 dark:text-zinc-100">
            </div>
        </div>
        <div class="flex flex-wrap items-center gap-3">
            <x-ui.select wire:model.live="statusFilter" class="w-auto text-sm">
                <option value="">All Order Statuses</option>
                <option value="DRAFT">Draft</option>
                <option value="CONFIRMED">Confirmed</option>
                <option value="PARTIALLY_FULFILLED">Partially Fulfilled</option>
                <option value="FULFILLED">Fulfilled</option>
                <option value="CLOSED">Closed</option>
                <option value="CANCELLED">Cancelled</option>
            </x-ui.select>

            <x-ui.select wire:model.live="fulfillmentFilter" class="w-auto text-sm">
                <option value="">All Fulfillment Statuses</option>
                <option value="UNFULFILLED">Unfulfilled</option>
                <option value="PARTIALLY_FULFILLED">Partially Fulfilled</option>
                <option value="FULFILLED">Fully Fulfilled</option>
            </x-ui.select>
        </div>
    </div>

    <!-- Table -->
    <div class="card-surface overflow-hidden rounded-2xl border border-[#E5E7EB] dark:border-zinc-800 shadow-xs">
        <div class="overflow-x-auto">
            <table class="table-standard min-w-full text-left border-collapse text-sm">
                <thead>
                    <tr class="border-b border-[#E5E7EB] dark:border-zinc-800 bg-zinc-50/50 dark:bg-zinc-900/50 text-xs font-semibold text-zinc-500 uppercase tracking-wider">
                        <th class="py-3.5 px-6">Sales Order No.</th>
                        <th class="py-3.5 px-6">Customer & PO</th>
                        <th class="py-3.5 px-6">Order Date</th>
                        <th class="py-3.5 px-6 text-right">Total Amount</th>
                        <th class="py-3.5 px-6 text-center">Order Status</th>
                        <th class="py-3.5 px-6 text-center">Fulfillment</th>
                        <th class="py-3.5 px-6 text-right">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[#E5E7EB] dark:divide-zinc-800/70">
                    @forelse($salesOrders as $so)
                        <tr class="hover:bg-zinc-50/60 dark:hover:bg-zinc-800/40 transition">
                            <td class="py-4 px-6">
                                <div class="font-medium text-apeiron-ink dark:text-white flex items-center gap-1.5 font-mono">
                                    <span>{{ $so->so_number }}</span>
                                </div>
                                <div class="text-xs text-zinc-400 mt-0.5">Source: {{ $so->order_source }}</div>
                            </td>
                            <td class="py-4 px-6">
                                <div class="font-medium text-zinc-800 dark:text-zinc-200">{{ $so->customer?->name }}</div>
                                @if($so->customer_po_number)
                                    <div class="text-xs text-indigo-600 dark:text-indigo-400 font-mono">PO: {{ $so->customer_po_number }}</div>
                                @else
                                    <div class="text-xs text-zinc-400 font-mono">{{ $so->customer?->customer_code }}</div>
                                @endif
                            </td>
                            <td class="py-4 px-6">
                                <div class="text-zinc-800 dark:text-zinc-200">{{ $so->order_date->format('d/m/Y') }}</div>
                                @if($so->expected_delivery_date)
                                    <div class="text-xs text-zinc-500">Delivery: {{ $so->expected_delivery_date->format('d/m/Y') }}</div>
                                @endif
                            </td>
                            <td class="py-4 px-6 text-right font-medium text-zinc-900 dark:text-white tabular-nums">
                                Rp {{ number_format((float) $so->total_amount, 2, ',', '.') }}
                            </td>
                            <td class="py-4 px-6 text-center">
                                @php
                                    $statusVariant = match($so->status->value) {
                                        'DRAFT' => 'neutral',
                                        'CONFIRMED' => 'info',
                                        'PARTIALLY_FULFILLED' => 'warning',
                                        'FULFILLED' => 'success',
                                        'CLOSED' => 'neutral',
                                        'CANCELLED' => 'danger',
                                        default => 'neutral',
                                    };
                                @endphp
                                <x-ui.badge variant="{{ $statusVariant }}" size="sm">
                                    {{ $so->status->value }}
                                </x-ui.badge>
                            </td>
                            <td class="py-4 px-6 text-center">
                                @php
                                    $fulfillVariant = match($so->fulfillment_status->value) {
                                        'UNFULFILLED' => 'neutral',
                                        'PARTIALLY_FULFILLED' => 'warning',
                                        'FULFILLED' => 'success',
                                        default => 'neutral',
                                    };
                                @endphp
                                <x-ui.badge variant="{{ $fulfillVariant }}" size="xs">
                                    {{ $so->fulfillment_status->value }}
                                </x-ui.badge>
                            </td>
                            <td class="py-4 px-6 text-right">
                                <div class="flex items-center justify-end gap-1.5">
                                    <button wire:click="viewOrder({{ $so->id }})" class="p-1.5 text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200 rounded-lg hover:bg-zinc-100 dark:hover:bg-zinc-800 transition" title="View SO Details">
                                        <span class="material-symbols-rounded text-lg">visibility</span>
                                    </button>

                                    @if(in_array($so->status->value, ['CONFIRMED', 'PARTIALLY_FULFILLED']))
                                        @can('fulfill', $so)
                                        <button wire:click="openFulfillModal({{ $so->id }})" class="p-1.5 text-emerald-600 hover:text-emerald-700 rounded-lg hover:bg-emerald-50 dark:hover:bg-emerald-950/40 transition" title="Process Fulfillment / Dispatch">
                                            <span class="material-symbols-rounded text-lg">local_shipping</span>
                                        </button>
                                        @endcan
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="py-12 text-center text-zinc-400">
                                <span class="material-symbols-rounded text-4xl block mb-2">shopping_cart</span>
                                No Sales Order data available.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($salesOrders->hasPages())
            <div class="p-4 border-t border-[#E5E7EB] dark:border-zinc-800">
                {{ $salesOrders->links() }}
            </div>
        @endif
    </div>

    <!-- Modal 1: Detail Sales Order -->
    <x-ui.modal wire:model="showDetailModal" maxWidth="4xl">
        <x-slot:title>
            @if($selectedSO)
                <div class="flex items-center gap-2">
                    <span>{{ $selectedSO->so_number }}</span>
                    @if($selectedSO->customer_po_number)
                        <x-ui.badge variant="info" size="xs">PO: {{ $selectedSO->customer_po_number }}</x-ui.badge>
                    @endif
                </div>
            @else
                Sales Order Details
            @endif
        </x-slot:title>

        <x-slot:content>
            @if($selectedSO)
            <div class="space-y-6">
                <div class="text-xs text-zinc-500 -mt-2">
                    Created by: {{ $selectedSO->createdBy?->name }} | Date: {{ $selectedSO->order_date->format('d/m/Y') }}
                </div>

                <!-- Status & Action Toolbar -->
                <div class="p-4 rounded-xl bg-zinc-50 dark:bg-zinc-800/40 border border-[#E5E7EB] dark:border-zinc-700/80 flex flex-wrap items-center justify-between gap-3">
                    <div class="flex items-center gap-4">
                        <div>
                            <span class="text-xs font-semibold text-zinc-500 uppercase block">Order Status</span>
                            <span class="font-bold text-zinc-900 dark:text-white">{{ $selectedSO->status->value }}</span>
                        </div>
                        <div class="border-l border-zinc-300 dark:border-zinc-700 pl-4">
                            <span class="text-xs font-semibold text-zinc-500 uppercase block">Fulfillment</span>
                            <span class="font-semibold text-zinc-800 dark:text-zinc-200">{{ $selectedSO->fulfillment_status->value }}</span>
                        </div>
                    </div>

                    <div class="flex items-center gap-2">
                        @if($selectedSO->status->value === 'DRAFT')
                            @can('confirm', $selectedSO)
                            <x-ui.button variant="primary" size="sm" wire:click="confirmOrder({{ $selectedSO->id }})" class="gap-1.5">
                                <span class="material-symbols-rounded text-sm">check_circle</span>
                                Confirm & Reserve Stock
                            </x-ui.button>
                            @endcan
                        @endif

                        @if(in_array($selectedSO->status->value, ['CONFIRMED', 'PARTIALLY_FULFILLED']))
                            @can('fulfill', $selectedSO)
                            <x-ui.button variant="success" size="sm" wire:click="openFulfillModal({{ $selectedSO->id }})" class="gap-1.5">
                                <span class="material-symbols-rounded text-sm">local_shipping</span>
                                Process Fulfillment
                            </x-ui.button>
                            @endcan
                        @endif

                        @if($selectedSO->status->canBeCancelled())
                            @can('cancel', $selectedSO)
                            <x-ui.button variant="danger" size="sm" wire:click="openCancelModal({{ $selectedSO->id }})">
                                Cancel / Close Remainder
                            </x-ui.button>
                            @endcan
                        @endif

                        <x-ui.button variant="secondary" size="sm" wire:click="repeatOrder({{ $selectedSO->id }})" class="gap-1">
                            <span class="material-symbols-rounded text-sm">repeat</span>
                            Repeat Order
                        </x-ui.button>
                    </div>
                </div>

                <!-- Snapshot Info -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-xs">
                    <div class="p-4 rounded-xl border border-zinc-200 dark:border-zinc-700 space-y-1">
                        <h4 class="font-bold text-zinc-900 dark:text-white mb-2 text-sm">Customer</h4>
                        <p><span class="text-zinc-500">Name:</span> <strong class="text-zinc-800 dark:text-zinc-200">{{ $selectedSO->customer?->name }}</strong></p>
                        <p><span class="text-zinc-500">Code:</span> <span class="font-mono">{{ $selectedSO->customer?->customer_code }}</span></p>
                        <p><span class="text-zinc-500">Terms:</span> {{ $selectedSO->payment_terms_days }} Days</p>
                    </div>
                    <div class="p-4 rounded-xl border border-zinc-200 dark:border-zinc-700 space-y-1">
                        <h4 class="font-bold text-zinc-900 dark:text-white mb-2 text-sm">Fulfillment History</h4>
                        <p><span class="text-zinc-500">Order Execution Documents:</span> {{ $selectedSO->orders->count() }} transactions</p>
                        @foreach($selectedSO->orders as $ord)
                            <p class="font-mono text-emerald-600 dark:text-emerald-400">#{{ $ord->order_code }} ({{ $ord->order_date }}) - Status: {{ $ord->order_status }}</p>
                        @endforeach
                    </div>
                </div>

                <!-- Lines Table -->
                <div class="border border-zinc-200 dark:border-zinc-700 rounded-xl overflow-hidden">
                    <table class="table-standard min-w-full text-left text-xs">
                        <thead class="bg-zinc-50 dark:bg-zinc-800 text-zinc-500 uppercase font-semibold">
                            <tr>
                                <th class="p-3">#</th>
                                <th class="p-3">Product</th>
                                <th class="p-3 text-right">Ordered</th>
                                <th class="p-3 text-right">Reserved</th>
                                <th class="p-3 text-right">Fulfilled</th>
                                <th class="p-3 text-right">Cancelled</th>
                                <th class="p-3 text-right">Remaining</th>
                                <th class="p-3 text-right">Total Value</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800 font-mono">
                            @foreach($selectedSO->lines as $line)
                                <tr>
                                    <td class="p-3 text-zinc-400">{{ $line->line_number }}</td>
                                    <td class="p-3 font-sans">
                                        <div class="font-medium text-zinc-800 dark:text-zinc-200">{{ $line->description ?: ($line->product?->product_name ?? $line->product?->name) }}</div>
                                        <div class="text-[10px] text-zinc-400 font-mono">{{ $line->product?->product_code ?? $line->product?->sku }}</div>
                                    </td>
                                    <td class="p-3 text-right tabular-nums">{{ number_format((float) $line->ordered_qty, 2) }}</td>
                                    <td class="p-3 text-right text-blue-600 tabular-nums">{{ number_format((float) $line->reserved_qty, 2) }}</td>
                                    <td class="p-3 text-right text-emerald-600 tabular-nums">{{ number_format((float) $line->fulfilled_qty, 2) }}</td>
                                    <td class="p-3 text-right text-rose-600 tabular-nums">{{ number_format((float) $line->cancelled_qty, 2) }}</td>
                                    <td class="p-3 text-right font-bold text-amber-600 tabular-nums">{{ number_format((float) $line->getRemainingQty(), 2) }}</td>
                                    <td class="p-3 text-right font-bold text-zinc-900 dark:text-white tabular-nums">Rp {{ number_format((float) $line->line_total, 2, ',', '.') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
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

    <!-- Modal 2: Fulfill SO -->
    <x-ui.modal wire:model="showFulfillModal" maxWidth="2xl">
        <x-slot:title>
            Process Order Fulfillment
        </x-slot:title>

        <x-slot:content>
            @if($selectedSO)
            <div class="space-y-4">
                <p class="text-xs text-zinc-500">Specify item quantities to be shipped/fulfilled in this delivery. Physical branch stock will be safely deducted via InventoryLedger.</p>

                <div class="space-y-3 max-h-60 overflow-y-auto">
                    @foreach($selectedSO->lines as $line)
                        @php $rem = (float) $line->getRemainingQty(); @endphp
                        @if($rem > 0)
                            <div class="p-3 rounded-xl border border-zinc-200 dark:border-zinc-700 flex items-center justify-between gap-4">
                                <div class="flex-1">
                                    <div class="font-semibold text-xs text-zinc-800 dark:text-zinc-200">{{ $line->description ?: ($line->product?->product_name ?? $line->product?->name) }}</div>
                                    <div class="text-[11px] text-zinc-400">Remaining to ship: <strong>{{ number_format($rem, 2) }}</strong></div>
                                </div>
                                <div class="w-32">
                                    <x-ui.input type="number" step="0.0001" max="{{ $rem }}" min="0" wire:model="fulfillmentQuantities.{{ $line->id }}" class="text-right font-mono text-xs" />
                                </div>
                            </div>
                        @endif
                    @endforeach
                </div>
            </div>
            @endif
        </x-slot:content>

        <x-slot:footer>
            <div class="flex justify-end gap-3">
                <x-ui.button variant="secondary" wire:click="closeFulfillModal">
                    Cancel
                </x-ui.button>
                <x-ui.button variant="success" wire:click="executeFulfillment">
                    Confirm Dispatch / Deduct Stock
                </x-ui.button>
            </div>
        </x-slot:footer>
    </x-ui.modal>

    <!-- Modal 3: Cancel SO -->
    <x-ui.modal wire:model="showCancelModal" maxWidth="md">
        <x-slot:title>
            Cancel / Close Remaining Order
        </x-slot:title>

        <x-slot:content>
            <div class="space-y-4">
                <p class="text-xs text-zinc-500">All unfulfilled quantities will be cancelled and stock reservations will be released.</p>
                <div>
                    <x-ui.label for="cancelReason">Cancellation Reason *</x-ui.label>
                    <textarea id="cancelReason" wire:model="cancelReason" rows="3" placeholder="Enter reason for order cancellation..." class="w-full text-sm rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 p-2.5 text-zinc-800 dark:text-zinc-100"></textarea>
                    @error('cancelReason') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                </div>
            </div>
        </x-slot:content>

        <x-slot:footer>
            <div class="flex justify-end gap-3">
                <x-ui.button variant="secondary" wire:click="closeCancelModal">
                    Cancel
                </x-ui.button>
                <x-ui.button variant="danger" wire:click="executeCancellation">
                    Confirm Cancellation
                </x-ui.button>
            </div>
        </x-slot:footer>
    </x-ui.modal>
</div>
