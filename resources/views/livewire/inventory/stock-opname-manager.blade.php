<div data-gsap="page-enter">
    @include('inventory.partials.sub-nav')
    {{-- Header & Primary Action --}}
    <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between mb-6 gap-4">
        <div>
            <h4 class="text-xl font-bold text-foreground tracking-tight">Stock Opname & Physical Reconciliation</h4>
            <p class="text-sm font-medium text-muted-foreground mt-1">
                Periodic physical stock counts with snapshot sequence pinning and drift gate (OD-04).
            </p>
        </div>
        <div class="flex flex-col sm:flex-row items-center gap-3 w-full sm:w-auto">
            <x-ui.button type="button" variant="primary" wire:click="openCreateModal" x-on:click="window.erpMotion?.pulseHighlight($el)">
                <span class="material-symbols-rounded text-[20px]">add</span> New Stock Opname Session
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
    <x-erp.filter-bar
        search-placeholder="Search opname number, notes..."
        search-model="search"
        :reset-method="null"
    >
        <x-slot:filters>
            <select wire:model.live="statusFilter"
                    class="h-9 px-3 rounded-medium border border-input bg-background text-foreground text-xs font-semibold focus-visible:ring-1 focus-visible:ring-ring outline-none">
                <option value="ALL">All Statuses</option>
                @foreach ($statusOptions as $s)
                    <option value="{{ $s->value }}">{{ $s->label() }}</option>
                @endforeach
            </select>
        </x-slot:filters>
    </x-erp.filter-bar>

    {{-- Stock Opnames Table --}}
    <x-erp.data-table
        loading-target="search, statusFilter"
        :is-empty="$opnames->isEmpty()"
        empty-title="No stock opname sessions found"
        empty-description="Start a new opname session to count and audit physical inventory."
        empty-icon="inventory"
        :colspan="6"
    >
                <x-slot:head>
                    <th scope="col" class="px-6 py-4 text-xs font-semibold text-muted-foreground uppercase tracking-wider">Opname #</th>
                    <th scope="col" class="px-6 py-4 text-xs font-semibold text-muted-foreground uppercase tracking-wider">Snapshot Date</th>
                    <th scope="col" class="px-6 py-4 text-xs font-semibold text-muted-foreground uppercase tracking-wider text-center">Status</th>
                    <th scope="col" class="px-6 py-4 text-xs font-semibold text-muted-foreground uppercase tracking-wider text-right font-sans tabular-nums numeric">Items</th>
                    <th scope="col" class="px-6 py-4 text-xs font-semibold text-muted-foreground uppercase tracking-wider">Counter / Approver</th>
                    <th scope="col" class="px-6 py-4 text-xs font-semibold text-muted-foreground uppercase tracking-wider text-right">Actions</th>
                </x-slot:head>

                @foreach ($opnames as $opname)
                    <tr class="hover:bg-secondary transition-colors duration-150">
                        <td class="px-6 py-4 whitespace-nowrap">
                            <span class="text-sm font-bold text-foreground">{{ $opname->opname_number }}</span>
                            <div class="text-xs text-muted-foreground">Created: {{ $opname->created_at->format('d M Y H:i') }}</div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-foreground">
                            @if ($opname->snapshot_taken_at)
                                <span class="font-medium text-emerald-700">{{ $opname->snapshot_taken_at->format('d M Y H:i') }}</span>
                            @else
                                <span class="text-xs text-muted-foreground italic">Not started</span>
                            @endif
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            @php
                                $opnameDot = match($opname->status->badgeType()) {
                                    'success' => 'bg-success',
                                    'warning' => 'bg-warning',
                                    'danger' => 'bg-destructive',
                                    default => 'bg-neutral',
                                };
                            @endphp
                            <span class="inline-flex items-center gap-1.5 text-xs text-foreground font-medium">
                                <span class="w-1.5 h-1.5 rounded-full {{ $opnameDot }}"></span>
                                {{ $opname->status->label() }}
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-semibold text-foreground text-right">
                            {{ $opname->items_count }} items
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-xs text-foreground">
                            <div>Count: <span class="font-medium">{{ $opname->submitter->name ?? '-' }}</span></div>
                            <div>Approve: <span class="font-medium">{{ $opname->approver->name ?? '-' }}</span></div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right">
                            <x-ui.table-actions>
                                <x-ui.table-action-item icon="visibility" wire:click="viewDetail({{ $opname->id }})" aria-label="View Details">
                                    View Details
                                </x-ui.table-action-item>
                                @if ($opname->status === \App\Enums\StockOpnameStatusEnum::Draft)
                                    <x-ui.table-action-item icon="play_circle" wire:click="startCounting({{ $opname->id }})" aria-label="Start Counting">
                                        Start Counting
                                    </x-ui.table-action-item>
                                @endif
                                @if ($opname->status === \App\Enums\StockOpnameStatusEnum::Counting)
                                    <x-ui.table-action-item icon="edit_note" wire:click="openCountModal({{ $opname->id }})" aria-label="Record Physical Counts">
                                        Record Physical Counts
                                    </x-ui.table-action-item>
                                    <x-ui.table-action-item icon="send" wire:click="submitForApproval({{ $opname->id }})" aria-label="Submit for Approval">
                                        Submit for Approval
                                    </x-ui.table-action-item>
                                @endif
                                @if ($opname->status === \App\Enums\StockOpnameStatusEnum::PendingApproval)
                                    <x-ui.table-action-item icon="check_circle" wire:click="approveOpname({{ $opname->id }})" aria-label="Approve Reconciliation">
                                        Approve Reconciliation
                                    </x-ui.table-action-item>
                                @endif
                                @if ($opname->status->canCancel())
                                    <x-ui.table-action-item icon="cancel" variant="destructive" wire:click="cancelOpname({{ $opname->id }})" wire:confirm="Cancel this stock opname session?" aria-label="Cancel Session">
                                        Cancel Session
                                    </x-ui.table-action-item>
                                @endif
                            </x-ui.table-actions>
                        </td>
                    </tr>
                @endforeach
                @if ($opnames->hasPages())
                    <x-slot:pagination>
                        {{ $opnames->links() }}
                    </x-slot:pagination>
                @endif
            </x-erp.data-table>

    {{-- MODAL 1: Create Opname Session Form --}}
    <x-ui.modal wire:model="showFormModal" maxWidth="2xl">
        <x-slot:header>
            <h5 class="text-lg font-bold text-foreground">New Stock Opname Session</h5>
        </x-slot:header>
        <x-slot:content>
            @if ($errors->has('general'))
                <x-ui.alert variant="destructive" class="mb-4">
                    {{ $errors->first('general') }}
                </x-ui.alert>
            @endif

            <div class="space-y-4">
                <div class="p-4 rounded-2xl bg-amber-50 border border-amber-200 text-amber-900 text-xs">
                    <p class="font-bold mb-1">Cut-Off & Sequence Pinning Mechanism (OD-04):</p>
                    <p>Once the session is created and counting begins, book balances and movement IDs are pinned. Cashier sales can continue, but intervening movements will be audited prior to final approval.</p>
                </div>

                <div class="flex items-center gap-3">
                    <input type="checkbox" id="all_items" wire:model.live="all_items" class="rounded border-border text-foreground focus:ring-ring">
                    <label for="all_items" class="text-sm font-semibold text-foreground cursor-pointer">
                        Count all branch catalog items (products & raw materials)
                    </label>
                </div>

                <div>
                    <label class="block text-xs font-bold text-foreground uppercase tracking-wider mb-1">Notes</label>
                    <textarea wire:model="notes" rows="2" placeholder="Opname objective, warehouse area, shift, etc." class="form-input w-full p-3 rounded-xl border border-border text-sm"></textarea>
                </div>
            </div>

            <div class="flex items-center justify-end gap-3 mt-6 pt-4 border-t border-border">
                <x-ui.button type="button" variant="secondary" wire:click="closeFormModal">Cancel</x-ui.button>
                <x-ui.button type="button" variant="primary" wire:click="saveOpname" x-on:click="window.erpMotion?.pulseHighlight($el)">Create Opname Draft</x-ui.button>
            </div>
        </x-slot:content>
    </x-ui.modal>

    {{-- MODAL 2: Physical Count Recording (Counting) --}}
    <x-ui.modal wire:model="showCountModal" maxWidth="4xl">
        <x-slot:header>
            @if ($activeOpname)
                <div>
                    <h5 class="text-lg font-bold text-foreground">Record Physical Counts #{{ $activeOpname->opname_number }}</h5>
                    <p class="text-xs text-muted-foreground">Snapshot Timestamp: {{ $activeOpname->snapshot_taken_at?->format('d M Y H:i:s') }}</p>
                </div>
            @endif
        </x-slot:header>
        <x-slot:content>
            @if ($activeOpname)
                <div class="max-h-96 overflow-y-auto mb-4">
                    <table class="table-standard w-full min-w-full text-left text-sm">
                        <thead class="bg-secondary text-xs font-semibold text-muted-foreground uppercase">
                            <tr>
                                <th class="p-3">Item</th>
                                <th class="p-3 text-right">Snapshot Balance</th>
                                <th class="p-3 text-right w-44">Counted Qty</th>
                                <th class="p-3">Notes</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            @foreach ($activeOpname->items as $item)
                                <tr>
                                    <td class="p-3">
                                        <div class="font-bold text-foreground">
                                            {{ $item->item_type === 'product' ? ($item->product?->product_name ?? $item->product?->name) : $item->ingredient?->name }}
                                        </div>
                                        <div class="text-xs text-muted-foreground uppercase">{{ $item->item_type }}</div>
                                    </td>
                                    <td class="p-3 text-right font-medium tabular-nums text-foreground">
                                        @if ($canViewExpectedStock)
                                            {{ number_format((float) $item->snapshot_on_hand, 2) }}
                                        @else
                                            <span class="text-xs text-muted-foreground italic font-mono">*** (Blind Count)</span>
                                        @endif
                                    </td>
                                    <td class="p-3 text-right">
                                        <input type="number" step="0.0001" min="0" wire:model="countedQuantities.{{ $item->id }}" placeholder="0.00" class="form-input w-full p-2 text-right tabular-nums rounded-lg border border-border text-sm font-semibold">
                                    </td>
                                    <td class="p-3">
                                        <input type="text" wire:model="countNotes.{{ $item->id }}" placeholder="Optional..." class="form-input w-full p-2 rounded-lg border border-border text-xs">
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="flex items-center justify-end gap-3 pt-4 border-t border-border">
                    <x-ui.button type="button" variant="secondary" wire:click="closeCountModal">Close</x-ui.button>
                    <x-ui.button type="button" variant="primary" wire:click="saveCounts" x-on:click="window.erpMotion?.pulseHighlight($el)">Save Counts</x-ui.button>
                </div>
            @endif
        </x-slot:content>
    </x-ui.modal>

    {{-- MODAL 3: Drift Detection Alert & Item-Level Resolution (OD-04) --}}
    <x-ui.modal wire:model="showDriftModal" maxWidth="3xl">
        <x-slot:header>
            <div class="text-rose-900">
                <h5 class="text-lg font-bold">Drift Detected (Intervening Transactions Occurred)</h5>
            </div>
        </x-slot:header>
        <x-slot:content>
            @if (!empty($driftedItems))
                <div class="mb-4 text-xs text-rose-700">
                    Per architectural decision <strong>OD-04</strong>, automatic approval is blocked because book balances have drifted since the snapshot was pinned. A per-item resolution must be selected:
                </div>

                <div class="space-y-4 max-h-96 overflow-y-auto mb-6">
                    @foreach ($driftedItems as $d)
                        <div class="p-4 rounded-2xl bg-rose-50 border border-rose-200 text-xs text-foreground flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                            <div>
                                <div class="font-bold text-sm text-foreground">{{ $d['name'] }}</div>
                                <div class="text-muted-foreground uppercase">{{ $d['item_type'] }} #{{ $d['item_id'] }}</div>
                                <div class="mt-2 grid grid-cols-3 gap-2">
                                    <div>Snapshot Balance: <strong class="text-amber-800">{{ $d['snapshot_on_hand'] }}</strong></div>
                                    <div>Current Balance: <strong class="text-rose-800">{{ $d['current_on_hand'] }}</strong></div>
                                    <div>Intervening Drift: <strong>{{ $d['drift_delta'] }}</strong></div>
                                </div>
                            </div>
                            <div class="flex items-center gap-2 shrink-0">
                                <button type="button" wire:click="resolveDriftItem({{ $d['item_id'] }}, 'RE_SNAPSHOT')" class="px-3 py-2 bg-white hover:bg-secondary text-foreground font-semibold rounded-xl border border-border shadow-sm">
                                    Recount (Re-Snapshot)
                                </button>
                                <button type="button" wire:click="resolveDriftItem({{ $d['item_id'] }}, 'REBASE_OVERRIDE')" class="px-3 py-2 bg-rose-600 hover:bg-rose-700 text-white font-semibold rounded-xl shadow-sm">
                                    Rebase Override
                                </button>
                            </div>
                        </div>
                    @endforeach
                </div>

                <div class="flex items-center justify-end gap-3 pt-4 border-t border-border">
                    <x-ui.button type="button" variant="secondary" wire:click="$set('showDriftModal', false)">Close & Review Later</x-ui.button>
                </div>
            @endif
        </x-slot:content>
    </x-ui.modal>

    {{-- MODAL 4: Stock Opname Session Details --}}
    <x-ui.modal wire:model="showDetailModal" maxWidth="4xl">
        <x-slot:header>
            @if ($selectedOpname)
                <div>
                    <h5 class="text-lg font-bold text-foreground">Stock Opname Details: {{ $selectedOpname->opname_number }}</h5>
                    <p class="text-xs text-muted-foreground">Status: {{ $selectedOpname->status->label() }}</p>
                </div>
            @endif
        </x-slot:header>
        <x-slot:content>
            @if ($selectedOpname)
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-6 p-4 rounded-2xl bg-secondary text-xs">
                    <div>
                        <span class="text-muted-foreground block">Snapshot Taken</span>
                        <span class="font-semibold text-foreground">{{ $selectedOpname->snapshot_taken_at?->format('d M Y H:i') ?? '-' }}</span>
                    </div>
                    <div>
                        <span class="text-muted-foreground block">Submitted</span>
                        <span class="font-semibold text-foreground">{{ $selectedOpname->submitted_at?->format('d M Y H:i') ?? '-' }} ({{ $selectedOpname->submitter->name ?? '-' }})</span>
                    </div>
                    <div>
                        <span class="text-muted-foreground block">Approved</span>
                        <span class="font-semibold text-foreground">{{ $selectedOpname->approved_at?->format('d M Y H:i') ?? '-' }} ({{ $selectedOpname->approver->name ?? '-' }})</span>
                    </div>
                    <div>
                        <span class="text-muted-foreground block">Notes</span>
                        <span class="font-semibold text-foreground">{{ $selectedOpname->notes ?? '-' }}</span>
                    </div>
                </div>

                <div class="max-h-96 overflow-y-auto mb-4">
                    <table class="table-standard w-full min-w-full text-left text-sm">
                        <thead class="bg-secondary text-xs font-semibold text-muted-foreground uppercase">
                            <tr>
                                <th class="p-3">Item</th>
                                <th class="p-3 text-right">Snapshot Balance</th>
                                <th class="p-3 text-right">Physical Count</th>
                                <th class="p-3 text-right">Variance</th>
                                <th class="p-3 text-center">Drift Resolution</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            @foreach ($selectedOpname->items as $item)
                                <tr>
                                    <td class="p-3">
                                        <div class="font-bold text-foreground">
                                            {{ $item->item_type === 'product' ? ($item->product?->product_name ?? $item->product?->name) : $item->ingredient?->name }}
                                        </div>
                                        <div class="text-xs text-muted-foreground uppercase">{{ $item->item_type }}</div>
                                    </td>
                                    <td class="p-3 text-right font-medium tabular-nums text-foreground">
                                        @if ($canViewExpectedStock)
                                            {{ number_format((float) $item->snapshot_on_hand, 2) }}
                                        @else
                                            <span class="text-xs text-muted-foreground italic font-mono">*** (Blind Count)</span>
                                        @endif
                                    </td>
                                    <td class="p-3 text-right font-medium tabular-nums text-foreground">
                                        {{ $item->counted_quantity !== null ? number_format((float) $item->counted_quantity, 2) : '-' }}
                                    </td>
                                    <td class="p-3 text-right font-bold tabular-nums {{ (float) $item->variance_quantity < 0 ? 'text-rose-600' : ((float) $item->variance_quantity > 0 ? 'text-emerald-600' : 'text-muted-foreground') }}">
                                        @if ($canViewExpectedStock || in_array($selectedOpname->status, [\App\Enums\StockOpnameStatusEnum::Approved, \App\Enums\StockOpnameStatusEnum::Completed]))
                                            {{ $item->variance_quantity !== null ? number_format((float) $item->variance_quantity, 2) : '-' }}
                                        @else
                                            <span class="text-xs text-muted-foreground italic font-mono">***</span>
                                        @endif
                                    </td>
                                    <td class="p-3 text-center text-xs">
                                        <span class="text-xs text-muted-foreground font-medium">
                                            {{ $item->drift_resolution_mode->value }}
                                        </span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="flex items-center justify-end gap-3 pt-4 border-t border-border">
                    <x-ui.button type="button" variant="secondary" wire:click="closeDetailModal">Close</x-ui.button>
                </div>
            @endif
        </x-slot:content>
    </x-ui.modal>
</div>
