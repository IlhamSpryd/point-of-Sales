<div class="space-y-6" data-gsap="page-enter">
    @include('customers.partials.sub-nav')

    <!-- Header -->
    <x-erp.page-header
        title="Credit Limits & Terms Management"
        description="Manage B2B commercial credit risk, payment terms (TOP), grace periods, and outstanding credit exposure.">
        <x-slot:actions>
            <x-ui.button href="{{ route('customers.index') }}" variant="secondary" icon="contacts">
                Customer Directory
            </x-ui.button>
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

    <!-- Metric Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        @php
            $totLimit = (float) ($summary?->total_limit ?? 0);
            $totReserved = (float) ($summary?->total_reserved ?? 0);
            $totInvoiced = (float) ($summary?->total_invoiced ?? 0);
            $totAvail = max(0, $totLimit - ($totReserved + $totInvoiced));
        @endphp
        <x-erp.stat-card
            label="Total Credit Limit"
            :value="'Rp ' . number_format($totLimit, 2, ',', '.')"
            subtext="Total credit ceiling across all customers"
            icon="payments" />
        <x-erp.stat-card
            label="Reserved Exposure"
            :value="'Rp ' . number_format($totReserved, 2, ',', '.')"
            subtext="Confirmed Sales Order commitments"
            icon="pending_actions" />
        <x-erp.stat-card
            label="Invoiced Exposure"
            :value="'Rp ' . number_format($totInvoiced, 2, ',', '.')"
            subtext="Outstanding uncollected receivables"
            icon="receipt_long" />
        <x-erp.stat-card
            label="Available Credit"
            :value="'Rp ' . number_format($totAvail, 2, ',', '.')"
            subtext="Risk-free ceiling for new orders"
            icon="verified_user" />
    </div>

    <!-- Filter & Toolbar -->
    <div class="card-surface p-4 rounded-2xl flex flex-col md:flex-row md:items-center justify-between gap-4 border border-[#E5E7EB] dark:border-zinc-800">
        <div class="flex-1 max-w-md">
            <x-ui.input
                type="text"
                wire:model.live.debounce.300ms="search"
                placeholder="Search customer code, name, or company..."
                icon="search" />
        </div>
        <div class="flex items-center gap-3">
            <x-ui.select wire:model.live="statusFilter">
                <option value="">All Credit Statuses</option>
                <option value="ACTIVE">Active</option>
                <option value="ON_HOLD">On Hold</option>
                <option value="SUSPENDED">Suspended</option>
            </x-ui.select>
        </div>
    </div>

    <!-- Table -->
    <div class="card-surface overflow-hidden rounded-2xl border border-[#E5E7EB] dark:border-zinc-800 shadow-sm">
        <div class="overflow-x-auto">
            <table class="table-standard w-full min-w-full text-left border-collapse text-sm text-zinc-600 dark:text-zinc-300">
                <thead>
                    <tr class="border-b border-[#E5E7EB] dark:border-zinc-800 bg-zinc-50/50 dark:bg-zinc-900/50 text-xs font-semibold text-zinc-500 uppercase tracking-wider">
                        <th class="py-3.5 px-4">Customer</th>
                        <th class="py-3.5 px-4 text-right">Credit Limit</th>
                        <th class="py-3.5 px-4 text-center">Terms & Grace</th>
                        <th class="py-3.5 px-4 text-right">Reserved SO</th>
                        <th class="py-3.5 px-4 text-right">Available Limit</th>
                        <th class="py-3.5 px-4 text-center">Credit Status</th>
                        <th class="py-3.5 px-4 text-center">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[#E5E7EB] dark:divide-zinc-800/70">
                    @forelse($customers as $cust)
                        @php
                            $acc = $cust->creditAccount;
                            $limitVal = (float) ($acc?->credit_limit ?? 0);
                            $reservedVal = (float) ($acc?->reserved_amount ?? 0);
                            $invoicedVal = (float) ($acc?->invoiced_amount ?? 0);
                            $availVal = max(0, $limitVal - ($reservedVal + $invoicedVal));
                            $statusVal = $acc?->status ?? 'ACTIVE';
                        @endphp
                        <tr class="hover:bg-zinc-50/50 dark:hover:bg-zinc-800/40 transition">
                            <td class="py-3.5 px-4">
                                <div class="font-semibold text-zinc-900 dark:text-white">{{ $cust->name }}</div>
                                <div class="text-xs text-zinc-400 font-mono">{{ $cust->customer_code }}</div>
                            </td>
                            <td class="py-3.5 px-4 text-right font-mono tabular-nums font-medium text-zinc-900 dark:text-white">
                                Rp {{ number_format($limitVal, 2, ',', '.') }}
                            </td>
                            <td class="py-3.5 px-4 text-center text-xs">
                                <span class="font-semibold text-zinc-800 dark:text-zinc-200">{{ $acc?->payment_terms_days ?? 0 }} days</span>
                                <span class="text-zinc-400 block text-[11px]">(Grace: {{ $acc?->grace_period_days ?? 0 }} days)</span>
                            </td>
                            <td class="py-3.5 px-4 text-right font-mono tabular-nums text-xs text-blue-600 dark:text-blue-400">
                                Rp {{ number_format($reservedVal, 2, ',', '.') }}
                            </td>
                            <td class="py-3.5 px-4 text-right font-mono tabular-nums text-xs font-semibold {{ $availVal > 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600' }}">
                                Rp {{ number_format($availVal, 2, ',', '.') }}
                            </td>
                            <td class="py-3.5 px-4 text-center">
                                @php
                                    $stBadge = match($statusVal) {
                                        'ACTIVE' => 'success',
                                        'ON_HOLD' => 'warning',
                                        'SUSPENDED' => 'danger',
                                        default => 'neutral'
                                    };
                                @endphp
                                <x-ui.badge :variant="$stBadge">
                                    {{ $statusVal }}
                                </x-ui.badge>
                            </td>
                            <td class="py-3.5 px-4 text-center">
                                <div class="flex items-center justify-center gap-1">
                                    <x-ui.button size="icon" variant="ghost" wire:click="openEditModal({{ $cust->id }})" title="Configure Limit & Terms" aria-label="Edit">
                                        <span class="material-symbols-rounded text-base text-zinc-600 dark:text-zinc-400">tune</span>
                                    </x-ui.button>
                                    <x-ui.button size="icon" variant="ghost" wire:click="openStatusModal({{ $cust->id }})" title="Change Credit Status" aria-label="Status">
                                        <span class="material-symbols-rounded text-base text-amber-500">lock_reset</span>
                                    </x-ui.button>
                                    <x-ui.button size="icon" variant="ghost" wire:click="openLogsModal({{ $cust->id }})" title="Credit Audit Trail" aria-label="Audit">
                                        <span class="material-symbols-rounded text-base text-zinc-500">history</span>
                                    </x-ui.button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="py-12 text-center text-zinc-400">
                                <span class="material-symbols-rounded text-4xl block mb-2">account_balance_wallet</span>
                                No customer credit accounts found.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($customers->hasPages())
            <div class="px-6 py-4 border-t border-zinc-200 dark:border-zinc-800">
                {{ $customers->links() }}
            </div>
        @endif
    </div>

    <!-- Modal Edit Limit & Terms -->
    <x-ui.modal wire:model="showEditModal" maxWidth="lg">
        <x-slot:title>
            Configure Credit Limit & Payment Terms
        </x-slot:title>

        <x-slot:content>
            @if($selectedCustomer)
            <div class="space-y-4 text-xs">
                <p class="text-xs text-zinc-500">Customer: <strong class="text-zinc-800 dark:text-zinc-200">{{ $selectedCustomer->name }}</strong> ({{ $selectedCustomer->customer_code }})</p>

                <div>
                    <x-ui.label for="creditLimit">Credit Limit Ceiling (Rp) *</x-ui.label>
                    <x-ui.input type="number" step="1000" min="0" id="creditLimit" wire:model="creditLimit" class="font-mono text-sm" />
                    @error('creditLimit') <span class="text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <x-ui.label for="paymentTermsDays">Payment Terms (Days) *</x-ui.label>
                        <x-ui.input type="number" min="0" max="365" id="paymentTermsDays" wire:model="paymentTermsDays" class="font-mono text-sm" />
                        <span class="text-[10px] text-zinc-400 mt-0.5 block">0 = Cash on Delivery / COD, 30 = Net 30</span>
                        @error('paymentTermsDays') <span class="text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <x-ui.label for="gracePeriodDays">Grace Period (Days) *</x-ui.label>
                        <x-ui.input type="number" min="0" max="90" id="gracePeriodDays" wire:model="gracePeriodDays" class="font-mono text-sm" />
                        <span class="text-[10px] text-zinc-400 mt-0.5 block">Tolerance days before automatic credit hold</span>
                        @error('gracePeriodDays') <span class="text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                    </div>
                </div>

                <div>
                    <x-ui.label for="limitChangeReason">Reason for Change *</x-ui.label>
                    <textarea id="limitChangeReason" wire:model="limitChangeReason" rows="2" placeholder="e.g., Adjustment following Q1 credit risk assessment..." class="w-full rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 p-2.5 text-zinc-800 dark:text-zinc-100 text-xs focus:ring-2 focus:ring-apeiron-ink/20"></textarea>
                    @error('limitChangeReason') <span class="text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                </div>
            </div>
            @endif
        </x-slot:content>

        <x-slot:footer>
            <div class="flex justify-end gap-3">
                <x-ui.button type="button" variant="secondary" wire:click="closeEditModal">
                    Cancel
                </x-ui.button>
                <x-ui.button type="button" variant="primary" wire:click="saveLimitAndTerms">
                    Save Changes
                </x-ui.button>
            </div>
        </x-slot:footer>
    </x-ui.modal>

    <!-- Modal Status Kredit -->
    <x-ui.modal wire:model="showStatusModal" maxWidth="md">
        <x-slot:title>
            Update Credit Account Status
        </x-slot:title>

        <x-slot:content>
            @if($selectedCustomer)
            <div class="space-y-4 text-xs">
                <p class="text-xs text-zinc-500">Customer: <strong class="text-zinc-800 dark:text-zinc-200">{{ $selectedCustomer->name }}</strong></p>

                <div>
                    <x-ui.label for="targetStatus">Select New Status *</x-ui.label>
                    <x-ui.select id="targetStatus" wire:model="targetStatus">
                        <option value="ACTIVE">ACTIVE (Credit Active & Ready for Ordering)</option>
                        <option value="ON_HOLD">ON_HOLD (Temporarily Held Due to Risk / Overdue)</option>
                        <option value="SUSPENDED">SUSPENDED (Fully Suspended by Management Policy)</option>
                    </x-ui.select>
                </div>

                <div>
                    <x-ui.label for="statusChangeReason">Reason for Status Change *</x-ui.label>
                    <textarea id="statusChangeReason" wire:model="statusChangeReason" rows="3" placeholder="Enter reason for status change..." class="w-full rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 p-2.5 text-zinc-800 dark:text-zinc-100 text-xs focus:ring-2 focus:ring-apeiron-ink/20"></textarea>
                    @error('statusChangeReason') <span class="text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                </div>
            </div>
            @endif
        </x-slot:content>

        <x-slot:footer>
            <div class="flex justify-end gap-3">
                <x-ui.button type="button" variant="secondary" wire:click="closeStatusModal">
                    Cancel
                </x-ui.button>
                <x-ui.button type="button" variant="warning" wire:click="saveStatus">
                    Confirm Status
                </x-ui.button>
            </div>
        </x-slot:footer>
    </x-ui.modal>

    <!-- Modal Riwayat Audit Kredit -->
    <x-ui.modal wire:model="showLogsModal" maxWidth="3xl">
        <x-slot:title>
            @if($selectedCustomer)
                Credit Audit Trail: {{ $selectedCustomer->name }}
            @else
                Credit Audit Trail
            @endif
        </x-slot:title>

        <x-slot:content>
            @if($selectedCustomer)
            <div class="p-2 space-y-3 max-h-[60vh] overflow-y-auto">
                @forelse($selectedCustomer->creditLogs as $log)
                    <div class="p-3.5 rounded-xl border border-zinc-200 dark:border-zinc-800 text-xs flex flex-col gap-1.5 bg-zinc-50/50 dark:bg-zinc-800/30">
                        <div class="flex items-center justify-between">
                            <span class="font-bold text-zinc-900 dark:text-white uppercase tracking-wider font-mono">{{ $log->event_type }}</span>
                            <span class="text-zinc-400 text-[11px]">{{ $log->created_at->format('d/m/Y H:i:s') }}</span>
                        </div>
                        <p class="text-zinc-600 dark:text-zinc-300">{{ $log->reason }}</p>
                        <div class="text-[11px] text-zinc-400">Processed by: <strong>{{ $log->actor?->name ?? 'System' }}</strong></div>
                    </div>
                @empty
                    <div class="py-8 text-center text-zinc-400 text-xs">No credit audit trail entries recorded.</div>
                @endforelse
            </div>
            @endif
        </x-slot:content>

        <x-slot:footer>
            <div class="flex justify-end">
                <x-ui.button type="button" variant="secondary" wire:click="closeLogsModal">
                    Close
                </x-ui.button>
            </div>
        </x-slot:footer>
    </x-ui.modal>
</div>
