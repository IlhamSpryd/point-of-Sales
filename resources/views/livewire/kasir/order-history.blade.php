<div x-data @keydown.escape.window="$wire.closeDetail()">

    {{-- ==================== SUMMARY ==================== --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-6">
        <div data-gsap="kpi-card">
            <x-erp.stat-card
                title="Total Orders (Filtered)"
                value="{{ number_format($this->summary->total_count) }}"
                icon="receipt_long"
                description="Total number of orders within selected period"
            />
        </div>
        <div data-gsap="kpi-card">
            <x-erp.stat-card
                title="Total Settled Revenue"
                value="Rp {{ number_format($this->summary->total_paid_amount, 0, ',', '.') }}"
                icon="payments"
                description="Total revenue from settled orders"
            />
        </div>
    </div>

    {{-- ==================== FILTER TOOLBAR ==================== --}}
    <x-erp.filter-bar search-placeholder="Search order code..." :reset-method="($search !== '' || $statusFilter !== 'all' || $datePreset !== 'today') ? 'resetFilters' : null">
        <x-slot:filters>
            <div class="relative w-full sm:w-56">
                <select wire:model.live="statusFilter"
                    class="h-9 w-full rounded-medium border border-input bg-background px-3 text-xs sm:text-sm text-foreground focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring outline-none">
                    <option value="all">All Statuses</option>
                    @foreach ($this->statusOptions as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <div class="flex flex-wrap items-center gap-1.5">
                @foreach ([
                    'today' => 'Today',
                    'yesterday' => 'Yesterday',
                    'week' => 'This Week',
                    'month' => 'This Month',
                    'custom' => 'Custom',
                ] as $value => $label)
                    <button type="button" wire:click="$set('datePreset', '{{ $value }}')"
                        class="h-9 px-3 rounded-medium text-xs font-medium transition-all {{ $datePreset === $value ? 'bg-primary text-primary-foreground shadow-2xs' : 'bg-muted text-muted-foreground hover:bg-muted/80 hover:text-foreground' }}">
                        {{ $label }}
                    </button>
                @endforeach

                @if ($datePreset === 'custom')
                    <div class="flex items-center gap-1.5 ml-1">
                        <input type="date" wire:model.live="startDate"
                            class="h-9 px-2.5 rounded-medium border border-input bg-background text-xs text-foreground focus-visible:ring-2 focus-visible:ring-ring outline-none">
                        <span class="text-muted-foreground text-xs">to</span>
                        <input type="date" wire:model.live="endDate"
                            class="h-9 px-2.5 rounded-medium border border-input bg-background text-xs text-foreground focus-visible:ring-2 focus-visible:ring-ring outline-none">
                    </div>
                @else
                    <span class="text-xs text-muted-foreground ml-1">({{ $this->dateRangeLabel }})</span>
                @endif
            </div>

            <div wire:loading.flex wire:target="search,statusFilter,datePreset,startDate,endDate"
                class="hidden items-center gap-2 text-xs text-muted-foreground shrink-0">
                <span class="h-3.5 w-3.5 rounded-full border-2 border-foreground/30 border-t-foreground animate-spin"></span>
                <span>Loading data...</span>
            </div>
        </x-slot:filters>
    </x-erp.filter-bar>

    {{-- ==================== ORDER HISTORY TABLE ==================== --}}
    <div class="flex flex-col flex-1 min-h-0">
        {{-- Desktop Table --}}
        <x-erp.data-table
            :hideOnMobile="true"
            loading-target="search, statusFilter, datePreset, startDate, endDate"
            :is-empty="$this->orders->isEmpty()"
            empty-title="No orders found."
            empty-description="Try adjusting the status filters or date range."
            empty-icon="receipt_long"
            :colspan="8"
        >
            <x-slot:head>
                <th scope="col" class="px-6 py-3.5 text-2xs font-semibold text-muted-foreground uppercase tracking-wider text-left">Order Code</th>
                <th scope="col" class="px-6 py-3.5 text-2xs font-semibold text-muted-foreground uppercase tracking-wider text-left">Cashier &amp; Type</th>
                <th scope="col" class="px-6 py-3.5 text-2xs font-semibold text-muted-foreground uppercase tracking-wider text-left">Items Summary</th>
                <th scope="col" class="px-6 py-3.5 text-2xs font-semibold text-muted-foreground uppercase tracking-wider text-left">Customer</th>
                <th scope="col" class="px-6 py-3.5 text-2xs font-semibold text-muted-foreground uppercase tracking-wider text-left">Payment</th>
                <th scope="col" class="px-6 py-3.5 text-2xs font-semibold text-muted-foreground uppercase tracking-wider text-right">Total Amount</th>
                <th scope="col" class="px-6 py-3.5 text-2xs font-semibold text-muted-foreground uppercase tracking-wider text-left">Status</th>
                <th scope="col" class="px-6 py-3.5 text-2xs font-semibold text-muted-foreground uppercase tracking-wider text-right">Actions</th>
            </x-slot:head>
                @foreach ($this->orders as $order)
                    @php
                        $stVal = $order->order_status->value ?? (string) $order->order_status;
                        $dotColor = match($stVal) {
                            'paid', 'completed' => 'bg-success',
                            'pending' => 'bg-warning',
                            'void', 'cancelled' => 'bg-destructive',
                            default => 'bg-neutral'
                        };
                    @endphp
                    <tr wire:key="order-{{ $order->id }}" class="hover:bg-gray-50/70 transition-colors">
                        <td class="px-6 py-4">
                            <div class="font-sans font-bold text-foreground text-xs">{{ $order->order_code }}</div>
                            <div class="text-[11px] text-gray-400 mt-0.5">{{ $order->created_at->format('d M Y, H:i') }}</div>
                        </td>
                        <td class="px-6 py-4">
                            <div class="text-xs font-bold text-foreground">{{ $order->user->name ?? 'Self-Order' }}</div>
                            <div class="text-[11px] text-gray-500 mt-0.5 flex items-center gap-1">
                                <span class="material-symbols-rounded text-[14px]">
                                    @if ($order->order_type?->value === 'takeaway')
                                        shopping_bag
                                    @elseif($order->order_type?->value === 'delivery')
                                        local_shipping
                                    @elseif($order->order_type?->value === 'self_order')
                                        touch_app
                                    @else
                                        table_restaurant
                                    @endif
                                </span>
                                <span>
                                    @if ($order->order_type?->value === 'takeaway')
                                        Takeaway
                                    @elseif($order->order_type?->value === 'delivery')
                                        Delivery
                                    @elseif($order->order_type?->value === 'self_order')
                                        Self-Order
                                    @else
                                        {{ $order->table->table_name ?? 'Dine-In' }}
                                    @endif
                                </span>
                            </div>
                        </td>
                        <td class="px-6 py-4">
                            <div class="text-xs text-gray-700 max-w-[200px] truncate font-medium">
                                {{ $order->orderItems->pluck('product.product_name')->filter()->take(2)->implode(', ') }}
                                @if ($order->order_items_count > 2)
                                    <span class="text-gray-400">+{{ $order->order_items_count - 2 }} more</span>
                                @endif
                            </div>
                            <div class="text-[11px] text-gray-400 mt-0.5">{{ $order->order_items_count }} item(s)</div>
                        </td>
                        <td class="px-6 py-4 text-xs font-medium text-gray-600">
                            {{ $order->customer->name ?? '—' }}
                        </td>
                        <td class="px-6 py-4">
                            <span class="text-xs text-foreground font-medium">
                                {{ $order->payment_method?->label() ?? '-' }}
                            </span>
                            @if ($order->payments->count() > 1)
                                <span class="block text-[10px] font-bold text-blue-600 mt-0.5 uppercase tracking-wider">
                                    Split {{ $order->payments->count() }} Methods
                                </span>
                            @endif
                        </td>
                        <td class="px-6 py-4 text-right">
                            <span class="text-xs font-black text-foreground tabular-nums">
                                Rp {{ number_format($order->order_amount, 0, ',', '.') }}
                            </span>
                        </td>
                        <td class="px-6 py-4">
                            <span class="inline-flex items-center gap-1.5 text-xs text-foreground font-medium">
                                <span class="w-1.5 h-1.5 rounded-full {{ $dotColor }}"></span>
                                {{ $order->order_status->label() }}
                            </span>
                        </td>
                        <td class="px-6 py-4 text-right whitespace-nowrap">
                            <x-ui.table-actions>
                                <x-ui.table-action-item icon="visibility" wire:click="viewDetail({{ $order->id }})" aria-label="View Order Details">
                                    View Details
                                </x-ui.table-action-item>
                                @if (in_array($stVal, ['paid', 'completed'], true))
                                    <x-ui.table-action-item icon="print" href="{{ route('transaction.receipt', $order->order_code) }}" target="_blank" rel="noopener" aria-label="Reprint Receipt">
                                        Reprint Receipt
                                    </x-ui.table-action-item>
                                @endif
                            </x-ui.table-actions>
                        </td>
                    </tr>
                @endforeach
            </x-erp.data-table>

        {{-- Mobile Cards --}}
        <div class="lg:hidden flex flex-col gap-2.5 p-4 overflow-y-auto flex-1">
            @forelse ($this->orders as $order)
                @php
                    $stVal = $order->order_status->value ?? (string) $order->order_status;
                    $dotColor = match($stVal) {
                        'paid', 'completed' => 'bg-success',
                        'pending' => 'bg-warning',
                        'void', 'cancelled' => 'bg-destructive',
                        default => 'bg-neutral'
                    };
                @endphp
                <div wire:key="order-card-{{ $order->id }}"
                    class="p-4 rounded-xl border border-border bg-white hover:bg-gray-50 transition-colors cursor-pointer shadow-xs"
                    wire:click="viewDetail({{ $order->id }})">
                    <div class="flex justify-between items-start">
                        <span class="font-sans font-bold text-xs text-foreground">{{ $order->order_code }}</span>
                        <span class="inline-flex items-center gap-1.5 text-xs text-foreground font-medium">
                            <span class="w-1.5 h-1.5 rounded-full {{ $dotColor }}"></span>
                            {{ $order->order_status->label() }}
                        </span>
                    </div>
                    <div class="flex justify-between items-end mt-2.5 pt-2 border-t border-border">
                        <div class="flex flex-col">
                            <span class="text-[11px] text-gray-400">{{ $order->created_at->format('d M, H:i') }}</span>
                            <span class="text-xs font-bold text-gray-700">{{ $order->user->name ?? 'Self-Order' }}</span>
                        </div>
                        <span class="font-black tabular-nums text-sm text-foreground">
                            Rp {{ number_format($order->order_amount, 0, ',', '.') }}
                        </span>
                    </div>
                </div>
            @empty
                <div class="py-8 text-center text-gray-400 text-xs flex flex-col items-center justify-center">
                    <div class="w-12 h-12 rounded-2xl bg-gray-50 flex items-center justify-center mb-2 border border-border">
                        <span class="material-symbols-rounded text-[24px] text-gray-400">receipt_long</span>
                    </div>
                    <p class="font-bold text-gray-600">No orders found.</p>
                </div>
            @endforelse
        </div>

        <div class="p-4 border-t border-border shrink-0">
            {{ $this->orders->links() }}
        </div>
    </div>

    {{-- ==================== ORDER DETAIL MODAL ==================== --}}
    @if ($this->selectedOrder)
        @php $detail = $this->selectedOrder; @endphp
        <x-ui.modal :open="true" maxWidth="2xl" role="dialog" onClose="$wire.closeDetail()">
            <x-slot:header>
                <div>
                    <h3 id="order-detail-title" class="font-black text-lg text-foreground font-sans">{{ $detail->order_code }}</h3>
                    <p class="text-xs text-muted-foreground mt-0.5">{{ $detail->created_at->format('d F Y, H:i') }} WIB</p>
                </div>
            </x-slot:header>

            <div class="space-y-5">
                <div class="grid grid-cols-2 gap-4 text-xs">
                    <div>
                        <p class="font-bold uppercase tracking-wider text-muted-foreground mb-1">Cashier</p>
                        <p class="font-bold text-foreground text-sm">{{ $detail->user->name ?? 'Self-Order' }}</p>
                    </div>
                    <div>
                        <p class="font-bold uppercase tracking-wider text-muted-foreground mb-1">Order Type</p>
                        <p class="font-bold text-foreground text-sm">
                            @if ($detail->order_type?->value === 'takeaway')
                                Takeaway
                            @elseif($detail->order_type?->value === 'delivery')
                                Delivery
                            @elseif($detail->order_type?->value === 'self_order')
                                Self-Order
                            @else
                                Dine-In &bull; {{ $detail->table->table_name ?? '-' }}
                            @endif
                        </p>
                    </div>
                    <div>
                        <p class="font-bold uppercase tracking-wider text-muted-foreground mb-1">Status</p>
                        @php
                            $dStVal = $detail->order_status->value ?? (string) $detail->order_status;
                            $dDotColor = match($dStVal) {
                                'paid', 'completed' => 'bg-success',
                                'pending' => 'bg-warning',
                                'void', 'cancelled' => 'bg-destructive',
                                default => 'bg-neutral'
                            };
                        @endphp
                        <span class="inline-flex items-center gap-1.5 text-xs text-foreground font-medium">
                            <span class="w-1.5 h-1.5 rounded-full {{ $dDotColor }}"></span>
                            {{ $detail->order_status->label() }}
                        </span>
                    </div>
                    @if ($detail->voided_at)
                        <div>
                            <p class="font-bold uppercase tracking-wider text-destructive mb-1">Voided</p>
                            <p class="font-bold text-destructive text-xs">by {{ $detail->voidedBy->name ?? '-' }} &bull; {{ $detail->voided_at->format('d M Y, H:i') }}</p>
                            @if ($detail->void_reason)
                                <p class="text-xs text-muted-foreground italic mt-0.5">"{{ $detail->void_reason }}"</p>
                            @endif
                        </div>
                    @endif
                </div>

                @if ($detail->customer)
                    <div class="bg-muted/50 rounded-xl p-3.5 border border-border">
                        <p class="text-[11px] font-bold uppercase tracking-wider text-muted-foreground mb-1.5">Customer</p>
                        <div class="flex items-center justify-between gap-3">
                            <div class="min-w-0">
                                <p class="font-bold text-xs text-foreground truncate">{{ $detail->customer->name }}</p>
                                <p class="text-[11px] text-muted-foreground">{{ $detail->customer->phone ?? '-' }}</p>
                            </div>
                        </div>
                    </div>
                @endif

                <div>
                    <p class="text-xs font-bold uppercase tracking-wider text-muted-foreground mb-2">Order Items</p>
                    <ul class="divide-y divide-border border border-border rounded-xl overflow-hidden text-xs">
                        @foreach ($detail->orderItems as $item)
                            @php
                                $returnedQty = $detail->orderReturns->flatMap->items->where('order_item_id', $item->id)->sum('quantity_returned');
                            @endphp
                            <li class="p-3 flex justify-between items-start gap-3 bg-card">
                                <div class="min-w-0">
                                    <p class="font-bold text-foreground">
                                        {{ $item->qty }}&times; {{ $item->product->product_name ?? 'Deleted Product' }}
                                        @if ($returnedQty > 0)
                                            <span class="text-warning font-semibold text-[11px] ml-1.5">(Returned: {{ $returnedQty }}x)</span>
                                        @endif
                                    </p>
                                    @if (!empty($item->options))
                                        <p class="text-[11px] text-muted-foreground mt-0.5">
                                            {{ collect($item->options)->pluck('name')->filter()->implode(', ') }}
                                        </p>
                                    @endif
                                    @if ($item->notes)
                                        <p class="text-[11px] italic text-muted-foreground mt-0.5">Note: {{ $item->notes }}</p>
                                    @endif
                                </div>
                                <span class="font-black text-foreground tabular-nums shrink-0">
                                    Rp {{ number_format($item->order_subtotal, 0, ',', '.') }}
                                </span>
                            </li>
                        @endforeach
                    </ul>
                </div>

                {{-- Summary Total --}}
                <div class="bg-muted/50 rounded-xl p-4 border border-border text-xs space-y-1.5">
                    <div class="flex justify-between text-muted-foreground"><span>Subtotal</span><span class="tabular-nums font-semibold">Rp {{ number_format($detail->subtotal_amount, 0, ',', '.') }}</span></div>
                    @if ($detail->discount_amount > 0)
                        <div class="flex justify-between text-destructive font-semibold"><span>Discount</span><span class="tabular-nums">- Rp {{ number_format($detail->discount_amount, 0, ',', '.') }}</span></div>
                    @endif
                    <div class="flex justify-between text-muted-foreground"><span>Tax</span><span class="tabular-nums font-semibold">Rp {{ number_format($detail->tax_amount, 0, ',', '.') }}</span></div>
                    @if ($detail->service_charge_amount > 0)
                        <div class="flex justify-between text-muted-foreground"><span>Service Charge</span><span class="tabular-nums font-semibold">Rp {{ number_format($detail->service_charge_amount, 0, ',', '.') }}</span></div>
                    @endif
                    <div class="flex justify-between font-black text-foreground text-sm pt-2 mt-1 border-t border-border">
                        <span>Total Amount</span><span class="tabular-nums">Rp {{ number_format($detail->order_amount, 0, ',', '.') }}</span>
                    </div>
                </div>
            </div>

            <x-slot:footer>
                <div class="flex flex-wrap gap-2 justify-end w-full">
                    <button type="button" wire:click="closeDetail"
                        class="min-h-[40px] px-4 rounded-xl border border-border bg-card text-xs font-semibold text-muted-foreground hover:bg-muted transition-all active:scale-95">
                        Close
                    </button>
                    @if ($detail->order_status->value === 'pending' && $detail->payment_method?->value !== 'cash')
                        <button type="button" wire:click="syncMidtransStatus({{ $detail->id }})"
                            wire:loading.attr="disabled" wire:target="syncMidtransStatus"
                            class="min-h-[40px] px-4 inline-flex items-center justify-center gap-1.5 rounded-xl bg-primary text-primary-foreground text-xs font-bold hover:bg-primary/90 transition-all active:scale-95 shadow-xs disabled:opacity-50">
                            <span wire:loading wire:target="syncMidtransStatus" class="h-3.5 w-3.5 animate-spin rounded-full border-2 border-white/30 border-t-white"></span>
                            <span wire:loading.remove wire:target="syncMidtransStatus" class="material-symbols-rounded text-[16px]">sync</span>
                            <span>Sync Midtrans Status</span>
                        </button>
                    @endif
                    @if (in_array($detail->order_status->value, ['paid', 'completed'], true))
                        <button type="button" wire:click="openReturnModal({{ $detail->id }})"
                            x-on:click="window.erpMotion?.pulseHighlight($el)"
                            class="min-h-[40px] px-4 inline-flex items-center justify-center gap-1.5 rounded-xl bg-amber-600 text-white text-xs font-bold hover:bg-amber-700 transition-all active:scale-95 shadow-xs">
                            <span class="material-symbols-rounded text-[16px]">keyboard_return</span> Return &amp; Refund
                        </button>
                    @endif
                    @if ($detail->order_status->value === 'paid' && auth()->user()->can('void', $detail))
                        <button type="button" wire:click="$set('voidingOrderId', {{ $detail->id }})"
                            x-on:click="window.erpMotion?.pulseHighlight($el)"
                            class="min-h-[40px] px-4 inline-flex items-center justify-center gap-1.5 rounded-xl bg-destructive text-destructive-foreground text-xs font-bold hover:bg-destructive/90 transition-all active:scale-95 shadow-xs">
                            <span class="material-symbols-rounded text-[16px]">cancel</span> Void Order
                        </button>
                    @endif
                    @if (in_array($detail->order_status->value, ['paid', 'completed'], true))
                        <button type="button" wire:click="requestReprint({{ $detail->id }})"
                            x-on:click="window.erpMotion?.pulseHighlight($el)"
                            class="min-h-[40px] px-4 inline-flex items-center justify-center gap-1.5 rounded-xl bg-primary text-primary-foreground text-xs font-bold hover:bg-primary/90 transition-all active:scale-95 shadow-xs">
                            <span class="material-symbols-rounded text-[16px]">print</span>
                            <span>{{ (($detail->print_count ?? 0) > 0) ? 'Reprint (#' . (($detail->print_count ?? 0) + 1) . ')' : 'Print Receipt' }}</span>
                        </button>
                    @endif
                </div>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    {{-- ==================== VOID ORDER MODAL ==================== --}}
    @if ($this->voidingOrderId)
        <x-ui.modal :open="true" maxWidth="md" role="alertdialog" onClose="$wire.set('voidingOrderId', null)" @keydown.escape.stop.prevent="$wire.set('voidingOrderId', null)">
            <x-slot:header>
                <div class="text-destructive">
                    <h3 id="void-modal-title" class="font-bold text-base">Void Order</h3>
                </div>
            </x-slot:header>

            <p class="text-xs text-muted-foreground mb-4">
                This action will mark the order as Void and automatically restore inventory. Requires a cancellation reason and Supervisor PIN verification.
            </p>

            <form id="void-order-form" wire:submit.prevent="voidOrder" class="space-y-4">
                <div>
                    <label for="void-reason-input" class="block text-xs font-bold uppercase tracking-wider text-muted-foreground mb-1.5">Cancellation Reason</label>
                    <textarea id="void-reason-input" wire:model="voidReason" rows="3" required minlength="5"
                        placeholder="Cancellation reason (minimum 5 characters)..."
                        class="w-full rounded-xl border border-border p-3 text-xs bg-background text-foreground focus:ring-1 focus:ring-destructive focus:border-destructive outline-none resize-none shadow-xs"></textarea>
                    @error('voidReason') <span class="text-xs text-destructive mt-1 block">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label for="void-pin-input" class="block text-xs font-bold uppercase tracking-wider text-destructive mb-1.5">Supervisor / Manager PIN</label>
                    <input type="password" id="void-pin-input" wire:model="supervisorPin" required maxlength="6"
                        placeholder="Supervisor PIN (6 digits)"
                        class="w-full rounded-xl border border-destructive/40 bg-destructive/10 px-3 py-2 text-center font-sans font-bold text-sm tracking-widest text-destructive focus:ring-1 focus:ring-destructive outline-none shadow-xs">
                    @error('supervisorPin') <span class="text-xs text-destructive mt-1 block">{{ $message }}</span> @enderror
                </div>
            </form>

            <x-slot:footer>
                <div class="flex justify-end gap-2 w-full">
                    <button type="button" wire:click="$set('voidingOrderId', null)"
                        class="px-4 py-2 rounded-xl border border-border text-xs font-semibold text-muted-foreground hover:bg-muted">
                        Cancel
                    </button>
                    <button type="submit" form="void-order-form"
                        wire:loading.attr="disabled" wire:target="voidOrder"
                        x-on:click="window.erpMotion?.pulseHighlight($el)"
                        class="px-4 py-2 rounded-xl bg-destructive text-destructive-foreground text-xs font-bold hover:bg-destructive/90 shadow-sm disabled:opacity-50 flex items-center gap-2">
                        <span wire:loading wire:target="voidOrder" class="h-3.5 w-3.5 animate-spin rounded-full border-2 border-white/30 border-t-white"></span>
                        <span wire:loading.remove wire:target="voidOrder">Confirm Void</span>
                        <span wire:loading wire:target="voidOrder">Processing...</span>
                    </button>
                </div>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    {{-- ==================== RETURN & REFUND MODAL ==================== --}}
    <x-ui.modal wire:model="showReturnModal" maxWidth="2xl" onClose="$wire.set('showReturnModal', false)">
        <x-slot:header>
            <div>
                <h3 id="return-modal-title" class="font-bold text-base text-foreground">
                    Return &amp; Refund Request
                </h3>
                <p class="text-xs text-muted-foreground mt-0.5">Select items to return, inventory disposition, and refund allocation method</p>
            </div>
        </x-slot:header>

        <div class="space-y-4 text-xs">
            @if ($returnErrorMessage)
                <div class="p-3 rounded-xl bg-destructive/10 border border-destructive/20 text-destructive text-xs font-semibold">
                    {{ $returnErrorMessage }}
                </div>
            @endif

            {{-- Item selection table --}}
            <div>
                <h4 class="text-xs font-bold uppercase tracking-wider text-muted-foreground mb-2">1. Select Items &amp; Disposition</h4>
                <div class="space-y-2.5">
                    @foreach ($returnItems as $id => $item)
                        <div class="p-3 rounded-xl border border-border bg-muted/30 space-y-2">
                            <div class="flex items-start justify-between gap-3">
                                <label class="flex items-center gap-2 cursor-pointer">
                                    <input type="checkbox" wire:model.live="returnItems.{{ $id }}.selected"
                                        class="rounded border-border text-foreground focus:ring-ring">
                                    <span class="text-xs font-bold text-foreground">{{ $item['product_name'] }}</span>
                                </label>
                                <span class="text-xs text-muted-foreground">Remaining: <strong class="text-foreground">{{ $item['remaining_qty'] }}</strong> pcs</span>
                            </div>

                            @if (!empty($item['selected']))
                                <div class="grid grid-cols-1 sm:grid-cols-3 gap-2 pt-2 border-t border-border text-xs">
                                    <div>
                                        <label class="block text-[11px] font-bold text-muted-foreground mb-1">Return Quantity</label>
                                        <input type="number" wire:model.live="returnItems.{{ $id }}.qty"
                                            min="1" max="{{ $item['remaining_qty'] }}"
                                            class="w-full rounded-lg border border-border bg-background px-2 py-1.5 text-xs font-bold text-foreground focus:ring-1 focus:ring-ring outline-none">
                                    </div>
                                    <div>
                                        <label class="block text-[11px] font-bold text-muted-foreground mb-1">Inventory Disposition</label>
                                        <select wire:model.live="returnItems.{{ $id }}.disposition"
                                            class="w-full rounded-lg border border-border bg-background px-2 py-1.5 text-xs font-bold text-foreground focus:ring-1 focus:ring-ring outline-none">
                                            @if (empty($item['is_recipe_product']))
                                                <option value="restock">Restock (Return to Shelf)</option>
                                            @endif
                                            <option value="quarantine">Quarantine (Inspection)</option>
                                            <option value="write_off_waste">Write-Off (Damaged / Waste)</option>
                                        </select>
                                        @if (!empty($item['is_recipe_product']))
                                            <span class="text-[10px] text-amber-700 dark:text-amber-400 block mt-0.5 font-medium">*F&amp;B Recipe: Restock Restricted</span>
                                        @endif
                                    </div>
                                    <div>
                                        <label class="block text-[11px] font-bold text-muted-foreground mb-1">Disposition Reason</label>
                                        <input type="text" wire:model="returnItems.{{ $id }}.disposition_reason"
                                            placeholder="Optional (e.g. spoiled, incorrect item)"
                                            class="w-full rounded-lg border border-border bg-background px-2 py-1.5 text-xs text-foreground focus:ring-1 focus:ring-ring outline-none">
                                    </div>
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- Refund Allocations --}}
            <div>
                <h4 class="text-xs font-bold uppercase tracking-wider text-muted-foreground mb-2">2. Refund Allocation</h4>
                <div class="space-y-2.5">
                    @foreach ($refundAllocations as $pId => $alloc)
                        <div class="p-3 rounded-xl border border-border bg-muted/30 space-y-2 text-xs">
                            <div class="flex justify-between items-center">
                                <span class="font-bold text-foreground">Payment #{{ $pId }} (Original Method: <strong class="uppercase">{{ $alloc['orig_method'] }}</strong>)</span>
                                <span class="text-muted-foreground">Max: <strong class="text-foreground">Rp {{ number_format($alloc['max_amount'], 0, ',', '.') }}</strong></span>
                            </div>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 pt-1">
                                <div>
                                    <label class="block text-[11px] font-bold text-muted-foreground mb-1">Refund Amount (Rp)</label>
                                    <input type="number" wire:model.live="refundAllocations.{{ $pId }}.amount"
                                        min="1" max="{{ $alloc['max_amount'] }}"
                                        class="w-full rounded-lg border border-border bg-background px-2 py-1.5 text-xs font-bold text-foreground focus:ring-1 focus:ring-ring outline-none">
                                </div>
                                <div>
                                    <label class="block text-[11px] font-bold text-muted-foreground mb-1">Refund Method</label>
                                    <select wire:model.live="refundAllocations.{{ $pId }}.refund_method"
                                        class="w-full rounded-lg border border-border bg-background px-2 py-1.5 text-xs font-bold text-foreground focus:ring-1 focus:ring-ring outline-none">
                                        <option value="{{ $alloc['orig_method'] }}">Original ({{ ucfirst($alloc['orig_method']) }})</option>
                                        @if ($alloc['orig_method'] !== 'cash')
                                            <option value="cash">Cash (Cash Refund Exception)</option>
                                        @endif
                                    </select>
                                </div>
                            </div>
                            @if ($alloc['refund_method'] === 'cash' && $alloc['orig_method'] !== 'cash')
                                <div>
                                    <label class="block text-[11px] text-destructive font-bold mb-1">Cash Refund Exception Reason *</label>
                                    <input type="text" wire:model="refundAllocations.{{ $pId }}.method_override_reason"
                                        placeholder="Required (e.g. EDC terminal offline, customer request)"
                                        class="w-full rounded-lg border border-destructive/40 bg-destructive/10 px-2 py-1.5 text-xs text-foreground focus:ring-1 focus:ring-destructive outline-none">
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- Form Footer Inputs --}}
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 pt-2 border-t border-border">
                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-muted-foreground mb-1">Return / Refund Reason *</label>
                    <input type="text" wire:model="returnReason" placeholder="Minimum 5 characters..."
                        class="w-full rounded-xl border border-border bg-background px-3 py-2 text-xs text-foreground focus:ring-1 focus:ring-ring outline-none shadow-xs">
                </div>
                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-destructive mb-1">Supervisor / Manager PIN *</label>
                    <input type="password" wire:model="returnSupervisorPin" maxlength="6" placeholder="Authorization PIN (6 digits)"
                        class="w-full rounded-xl border border-destructive/40 bg-destructive/10 px-3 py-2 text-xs text-center font-sans font-bold tracking-widest text-destructive focus:ring-1 focus:ring-destructive outline-none shadow-xs">
                </div>
            </div>
        </div>

        <x-slot:footer>
            <div class="flex justify-end gap-2 w-full">
                <button type="button" wire:click="closeReturnModal"
                    class="px-4 py-2 rounded-xl border border-border text-xs font-semibold text-muted-foreground hover:bg-muted">
                    Cancel
                </button>
                <button type="button" wire:click="processReturn"
                    wire:loading.attr="disabled" wire:target="processReturn"
                    x-on:click="window.erpMotion?.pulseHighlight($el)"
                    class="px-5 py-2 rounded-xl bg-amber-600 text-white text-xs font-bold hover:bg-amber-700 shadow-sm disabled:opacity-50 flex items-center gap-2">
                    <span wire:loading wire:target="processReturn" class="h-3.5 w-3.5 animate-spin rounded-full border-2 border-white/30 border-t-white"></span>
                    <span wire:loading.remove wire:target="processReturn">Process Return &amp; Refund</span>
                    <span wire:loading wire:target="processReturn">Processing...</span>
                </button>
            </div>
        </x-slot:footer>
    </x-ui.modal>

    {{-- ==================== REPRINT RECEIPT MODAL ==================== --}}
    <x-ui.modal wire:model="showReprintModal" maxWidth="md" onClose="$wire.set('showReprintModal', false)">
        <x-slot:header>
            <div class="text-foreground">
                <h3 id="reprint-modal-title" class="font-bold text-base">
                    Reprint Receipt (#{{ $reprintOrderPrintCount + 1 }})
                </h3>
            </div>
        </x-slot:header>

        <div class="space-y-4 text-xs">
            <div class="p-3 bg-amber-500/10 border border-amber-500/20 rounded-xl text-xs text-amber-900 dark:text-amber-300">
                This receipt has already been printed <strong>{{ $reprintOrderPrintCount }} time(s)</strong>. The subsequent print will bear watermark <strong>*** COPY / REPRINT #{{ $reprintOrderPrintCount + 1 }} ***</strong> and will be logged in the audit trail.
            </div>

            <div class="space-y-3">
                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-muted-foreground mb-1">Reprint Reason *</label>
                    <input type="text" wire:model="reprintReason" placeholder="e.g. Paper jam, customer / cashier request..."
                        class="w-full rounded-xl border border-border bg-background px-3 py-2 text-xs text-foreground focus:ring-1 focus:ring-ring outline-none shadow-xs">
                    @error('reprintReason') <span class="text-xs text-destructive mt-1 block">{{ $message }}</span> @enderror
                </div>

                @if ($reprintOrderPrintCount >= 2)
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wider text-destructive mb-1">Supervisor PIN *</label>
                        <input type="password" wire:model="reprintSupervisorPin" maxlength="6" placeholder="Supervisor PIN for &gt;2x reprint"
                            class="w-full rounded-xl border border-destructive/40 bg-destructive/10 px-3 py-2 text-center font-sans font-bold text-xs tracking-widest text-destructive focus:ring-1 focus:ring-destructive outline-none shadow-xs">
                        @error('reprintSupervisorPin') <span class="text-xs text-destructive mt-1 block">{{ $message }}</span> @enderror
                    </div>
                @endif
            </div>
        </div>

        <x-slot:footer>
            <div class="flex justify-end gap-2 w-full">
                <button type="button" wire:click="closeReprintModal"
                    class="px-4 py-2 rounded-xl border border-border text-xs font-semibold text-muted-foreground hover:bg-muted">
                    Cancel
                </button>
                <button type="button" wire:click="confirmReprint"
                    wire:loading.attr="disabled" wire:target="confirmReprint"
                    x-on:click="window.erpMotion?.pulseHighlight($el)"
                    class="px-5 py-2 rounded-xl bg-primary text-primary-foreground text-xs font-bold hover:bg-primary/90 shadow-sm disabled:opacity-50 flex items-center gap-2">
                    <span wire:loading wire:target="confirmReprint" class="h-3.5 w-3.5 animate-spin rounded-full border-2 border-white/30 border-t-white"></span>
                    <span wire:loading.remove wire:target="confirmReprint">Print Now</span>
                    <span wire:loading wire:target="confirmReprint">Processing...</span>
                </button>
            </div>
        </x-slot:footer>
    </x-ui.modal>
</div>
