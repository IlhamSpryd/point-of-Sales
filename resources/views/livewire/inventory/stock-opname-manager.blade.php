<div data-gsap="page-enter">
    @include('inventory.partials.sub-nav')
    {{-- Header & Primary Action --}}
    <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between mb-6 gap-4">
        <div>
            <h4 class="text-xl font-bold text-apeiron-ink tracking-tight">Stock Opname & Physical Reconciliation</h4>
            <p class="text-sm font-medium text-apeiron-muted mt-1">
                Periodic physical stock counts with snapshot sequence pinning and drift gate (OD-04).
            </p>
        </div>
        <div class="flex flex-col sm:flex-row items-center gap-3 w-full sm:w-auto">
            <x-ui.button type="button" variant="primary" wire:click="openCreateModal" x-on:click="window.erpMotion?.pulseHighlight($el)">
                <span class="material-symbols-rounded text-[18px]">add</span> New Stock Opname Session
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
            <input type="text" wire:model.live.debounce.300ms="search" placeholder="Search opname number, notes..."
                   class="form-input w-full pl-10 pr-4 py-2 rounded-2xl border border-apeiron-border bg-white text-apeiron-ink placeholder-apeiron-muted focus:outline-none focus:ring-2 focus:ring-apeiron-ink shadow-sm text-sm">
            <div class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none text-apeiron-muted">
                <span class="material-symbols-rounded text-[18px]">search</span>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-3 w-full sm:w-auto">
            <div class="relative w-full sm:w-60">
                <select wire:model.live="statusFilter"
                        class="form-input w-full pl-4 pr-8 py-2 rounded-2xl border border-apeiron-border bg-white text-apeiron-ink focus:outline-none focus:ring-2 focus:ring-apeiron-ink text-sm shadow-sm appearance-none">
                    <option value="ALL">All Statuses</option>
                    @foreach ($statusOptions as $s)
                        <option value="{{ $s->value }}">{{ $s->label() }}</option>
                    @endforeach
                </select>
                <div class="absolute inset-y-0 right-0 flex items-center px-2 pointer-events-none text-apeiron-muted">
                    <span class="material-symbols-rounded text-[18px]">keyboard_arrow_down</span>
                </div>
            </div>
        </div>
    </div>

    {{-- Stock Opnames Table --}}
    <div class="card-surface overflow-hidden w-full shrink-0">
        <div class="overflow-x-auto">
            <x-data-table>
                <x-slot:head>
                    <th scope="col" class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider">Opname #</th>
                    <th scope="col" class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider">Snapshot Date</th>
                    <th scope="col" class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider text-center">Status</th>
                    <th scope="col" class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider text-right">Items</th>
                    <th scope="col" class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider">Counter / Approver</th>
                    <th scope="col" class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider text-center">Actions</th>
                </x-slot:head>

                @forelse ($opnames as $opname)
                    <tr class="hover:bg-apeiron-surface transition-colors duration-150">
                        <td class="px-6 py-4 whitespace-nowrap">
                            <span class="text-sm font-bold text-apeiron-ink">{{ $opname->opname_number }}</span>
                            <div class="text-xs text-apeiron-muted">Created: {{ $opname->created_at->format('d M Y H:i') }}</div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-apeiron-ink">
                            @if ($opname->snapshot_taken_at)
                                <span class="font-medium text-emerald-700">{{ $opname->snapshot_taken_at->format('d M Y H:i') }}</span>
                            @else
                                <span class="text-xs text-apeiron-muted italic">Not started</span>
                            @endif
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-center">
                            <x-ui.badge :variant="$opname->status->badgeType()">
                                {{ $opname->status->label() }}
                            </x-ui.badge>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-semibold text-apeiron-ink text-right">
                            {{ $opname->items_count }} items
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-xs text-apeiron-ink">
                            <div>Count: <span class="font-medium">{{ $opname->submitter->name ?? '-' }}</span></div>
                            <div>Approve: <span class="font-medium">{{ $opname->approver->name ?? '-' }}</span></div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-center text-sm font-medium">
                            <div class="flex items-center justify-center gap-2">
                                <button type="button" wire:click="viewDetail({{ $opname->id }})" class="p-1 hover:text-apeiron-ink text-apeiron-muted transition-colors" title="View Details">
                                    <span class="material-symbols-rounded text-[20px]">visibility</span>
                                </button>

                                @if ($opname->status === \App\Enums\StockOpnameStatusEnum::Draft)
                                    <button type="button" wire:click="startCounting({{ $opname->id }})" class="p-1 text-amber-600 hover:text-amber-800 transition-colors" title="Start Counting (Pin Snapshot)">
                                        <span class="material-symbols-rounded text-[20px]">play_circle</span>
                                    </button>
                                @endif

                                @if ($opname->status === \App\Enums\StockOpnameStatusEnum::Counting)
                                    <button type="button" wire:click="openCountModal({{ $opname->id }})" class="p-1 text-blue-600 hover:text-blue-800 transition-colors" title="Record Physical Counts">
                                        <span class="material-symbols-rounded text-[20px]">edit_note</span>
                                    </button>
                                    <button type="button" wire:click="submitForApproval({{ $opname->id }})" class="p-1 text-emerald-600 hover:text-emerald-800 transition-colors" title="Submit for Approval">
                                        <span class="material-symbols-rounded text-[20px]">send</span>
                                    </button>
                                @endif

                                @if ($opname->status === \App\Enums\StockOpnameStatusEnum::PendingApproval)
                                    <button type="button" wire:click="approveOpname({{ $opname->id }})" class="p-1 text-emerald-700 hover:text-emerald-900 transition-colors" title="Approve Reconciliation (Enforce Drift Gate)">
                                        <span class="material-symbols-rounded text-[20px]">check_circle</span>
                                    </button>
                                @endif

                                @if ($opname->status->canCancel())
                                    <button type="button" wire:click="cancelOpname({{ $opname->id }})" wire:confirm="Cancel this stock opname session?" class="p-1 text-rose-500 hover:text-rose-700 transition-colors" title="Cancel Session">
                                        <span class="material-symbols-rounded text-[20px]">cancel</span>
                                    </button>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-6 py-8 text-center text-sm text-apeiron-muted">
                            No stock opname sessions found.
                        </td>
                    </tr>
                @endforelse
            </x-data-table>
        </div>

        @if ($opnames->hasPages())
            <div class="px-6 py-4 border-t border-apeiron-border">
                {{ $opnames->links() }}
            </div>
        @endif
    </div>

    {{-- MODAL 1: Create Opname Session Form --}}
    <x-ui.modal wire:model="showFormModal" maxWidth="2xl">
        <x-slot:header>
            <h5 class="text-lg font-bold text-apeiron-ink">New Stock Opname Session</h5>
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
                    <input type="checkbox" id="all_items" wire:model.live="all_items" class="rounded border-apeiron-border text-apeiron-ink focus:ring-apeiron-ink">
                    <label for="all_items" class="text-sm font-semibold text-apeiron-ink cursor-pointer">
                        Count all branch catalog items (products & raw materials)
                    </label>
                </div>

                <div>
                    <label class="block text-xs font-bold text-apeiron-ink uppercase tracking-wider mb-1">Notes</label>
                    <textarea wire:model="notes" rows="2" placeholder="Opname objective, warehouse area, shift, etc." class="form-input w-full p-3 rounded-xl border border-apeiron-border text-sm"></textarea>
                </div>
            </div>

            <div class="flex items-center justify-end gap-3 mt-6 pt-4 border-t border-apeiron-border">
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
                    <h5 class="text-lg font-bold text-apeiron-ink">Record Physical Counts #{{ $activeOpname->opname_number }}</h5>
                    <p class="text-xs text-apeiron-muted">Snapshot Timestamp: {{ $activeOpname->snapshot_taken_at?->format('d M Y H:i:s') }}</p>
                </div>
            @endif
        </x-slot:header>
        <x-slot:content>
            @if ($activeOpname)
                <div class="max-h-96 overflow-y-auto mb-4">
                    <table class="table-standard w-full min-w-full text-left text-sm">
                        <thead class="bg-apeiron-surface text-xs font-semibold text-apeiron-muted uppercase">
                            <tr>
                                <th class="p-3">Item</th>
                                <th class="p-3 text-right">Snapshot Balance</th>
                                <th class="p-3 text-right w-44">Counted Qty</th>
                                <th class="p-3">Notes</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-apeiron-border">
                            @foreach ($activeOpname->items as $item)
                                <tr>
                                    <td class="p-3">
                                        <div class="font-bold text-apeiron-ink">
                                            {{ $item->item_type === 'product' ? ($item->product?->product_name ?? $item->product?->name) : $item->ingredient?->name }}
                                        </div>
                                        <div class="text-xs text-apeiron-muted uppercase">{{ $item->item_type }}</div>
                                    </td>
                                    <td class="p-3 text-right font-medium tabular-nums text-apeiron-ink">
                                        {{ number_format((float) $item->snapshot_on_hand, 2) }}
                                    </td>
                                    <td class="p-3 text-right">
                                        <input type="number" step="0.0001" min="0" wire:model="countedQuantities.{{ $item->id }}" placeholder="0.00" class="form-input w-full p-2 text-right tabular-nums rounded-lg border border-apeiron-border text-sm font-semibold">
                                    </td>
                                    <td class="p-3">
                                        <input type="text" wire:model="countNotes.{{ $item->id }}" placeholder="Optional..." class="form-input w-full p-2 rounded-lg border border-apeiron-border text-xs">
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="flex items-center justify-end gap-3 pt-4 border-t border-apeiron-border">
                    <x-ui.button type="button" variant="secondary" wire:click="closeCountModal">Close</x-ui.button>
                    <x-ui.button type="button" variant="primary" wire:click="saveCounts" x-on:click="window.erpMotion?.pulseHighlight($el)">Save Counts</x-ui.button>
                </div>
            @endif
        </x-slot:content>
    </x-ui.modal>

    {{-- MODAL 3: Drift Detection Alert & Item-Level Resolution (OD-04) --}}
    <x-ui.modal wire:model="showDriftModal" maxWidth="3xl">
        <x-slot:header>
            <div class="flex items-center gap-3">
                <span class="material-symbols-rounded text-rose-600 text-2xl">warning</span>
                <h5 class="text-lg font-bold text-rose-900">Drift Detected (Intervening Transactions Occurred)</h5>
            </div>
        </x-slot:header>
        <x-slot:content>
            @if (!empty($driftedItems))
                <div class="mb-4 text-xs text-rose-700">
                    Per architectural decision <strong>OD-04</strong>, automatic approval is blocked because book balances have drifted since the snapshot was pinned. A per-item resolution must be selected:
                </div>

                <div class="space-y-4 max-h-96 overflow-y-auto mb-6">
                    @foreach ($driftedItems as $d)
                        <div class="p-4 rounded-2xl bg-rose-50 border border-rose-200 text-xs text-apeiron-ink flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                            <div>
                                <div class="font-bold text-sm text-apeiron-ink">{{ $d['name'] }}</div>
                                <div class="text-apeiron-muted uppercase">{{ $d['item_type'] }} #{{ $d['item_id'] }}</div>
                                <div class="mt-2 grid grid-cols-3 gap-2">
                                    <div>Snapshot Balance: <strong class="text-amber-800">{{ $d['snapshot_on_hand'] }}</strong></div>
                                    <div>Current Balance: <strong class="text-rose-800">{{ $d['current_on_hand'] }}</strong></div>
                                    <div>Intervening Drift: <strong>{{ $d['drift_delta'] }}</strong></div>
                                </div>
                            </div>
                            <div class="flex items-center gap-2 shrink-0">
                                <button type="button" wire:click="resolveDriftItem({{ $d['item_id'] }}, 'RE_SNAPSHOT')" class="px-3 py-2 bg-white hover:bg-apeiron-surface text-apeiron-ink font-semibold rounded-xl border border-apeiron-border shadow-sm">
                                    Recount (Re-Snapshot)
                                </button>
                                <button type="button" wire:click="resolveDriftItem({{ $d['item_id'] }}, 'REBASE_OVERRIDE')" class="px-3 py-2 bg-rose-600 hover:bg-rose-700 text-white font-semibold rounded-xl shadow-sm">
                                    Rebase Override
                                </button>
                            </div>
                        </div>
                    @endforeach
                </div>

                <div class="flex items-center justify-end gap-3 pt-4 border-t border-apeiron-border">
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
                    <h5 class="text-lg font-bold text-apeiron-ink">Stock Opname Details: {{ $selectedOpname->opname_number }}</h5>
                    <p class="text-xs text-apeiron-muted">Status: {{ $selectedOpname->status->label() }}</p>
                </div>
            @endif
        </x-slot:header>
        <x-slot:content>
            @if ($selectedOpname)
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-6 p-4 rounded-2xl bg-apeiron-surface text-xs">
                    <div>
                        <span class="text-apeiron-muted block">Snapshot Taken</span>
                        <span class="font-semibold text-apeiron-ink">{{ $selectedOpname->snapshot_taken_at?->format('d M Y H:i') ?? '-' }}</span>
                    </div>
                    <div>
                        <span class="text-apeiron-muted block">Submitted</span>
                        <span class="font-semibold text-apeiron-ink">{{ $selectedOpname->submitted_at?->format('d M Y H:i') ?? '-' }} ({{ $selectedOpname->submitter->name ?? '-' }})</span>
                    </div>
                    <div>
                        <span class="text-apeiron-muted block">Approved</span>
                        <span class="font-semibold text-apeiron-ink">{{ $selectedOpname->approved_at?->format('d M Y H:i') ?? '-' }} ({{ $selectedOpname->approver->name ?? '-' }})</span>
                    </div>
                    <div>
                        <span class="text-apeiron-muted block">Notes</span>
                        <span class="font-semibold text-apeiron-ink">{{ $selectedOpname->notes ?? '-' }}</span>
                    </div>
                </div>

                <div class="max-h-96 overflow-y-auto mb-4">
                    <table class="table-standard w-full min-w-full text-left text-sm">
                        <thead class="bg-apeiron-surface text-xs font-semibold text-apeiron-muted uppercase">
                            <tr>
                                <th class="p-3">Item</th>
                                <th class="p-3 text-right">Snapshot Balance</th>
                                <th class="p-3 text-right">Physical Count</th>
                                <th class="p-3 text-right">Variance</th>
                                <th class="p-3 text-center">Drift Resolution</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-apeiron-border">
                            @foreach ($selectedOpname->items as $item)
                                <tr>
                                    <td class="p-3">
                                        <div class="font-bold text-apeiron-ink">
                                            {{ $item->item_type === 'product' ? ($item->product?->product_name ?? $item->product?->name) : $item->ingredient?->name }}
                                        </div>
                                        <div class="text-xs text-apeiron-muted uppercase">{{ $item->item_type }}</div>
                                    </td>
                                    <td class="p-3 text-right font-medium tabular-nums text-apeiron-ink">
                                        {{ number_format((float) $item->snapshot_on_hand, 2) }}
                                    </td>
                                    <td class="p-3 text-right font-medium tabular-nums text-apeiron-ink">
                                        {{ $item->counted_quantity !== null ? number_format((float) $item->counted_quantity, 2) : '-' }}
                                    </td>
                                    <td class="p-3 text-right font-bold tabular-nums {{ (float) $item->variance_quantity < 0 ? 'text-rose-600' : ((float) $item->variance_quantity > 0 ? 'text-emerald-600' : 'text-apeiron-muted') }}">
                                        {{ $item->variance_quantity !== null ? number_format((float) $item->variance_quantity, 2) : '-' }}
                                    </td>
                                    <td class="p-3 text-center text-xs">
                                        <span class="px-2 py-1 rounded bg-apeiron-surface border border-apeiron-border">
                                            {{ $item->drift_resolution_mode->value }}
                                        </span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="flex items-center justify-end gap-3 pt-4 border-t border-apeiron-border">
                    <x-ui.button type="button" variant="secondary" wire:click="closeDetailModal">Close</x-ui.button>
                </div>
            @endif
        </x-slot:content>
    </x-ui.modal>
</div>
