<div x-data="{
    openCloseModal: false,
    expectedCash: {{ $expectedCash ?? 0 }},
    countedCash: 0,
    get discrepancy() {
        return (parseInt(this.countedCash) || 0) - this.expectedCash;
    }
}" data-gsap="page-enter">
    @if(!$activeShift)
        {{-- Form Open Shift --}}
        <div class="bg-white rounded-3xl p-8 border border-[#E5E7EB] shadow-sm max-w-lg mx-auto text-center" data-gsap="kpi-card">
            <div class="w-16 h-16 bg-gray-50 rounded-2xl flex items-center justify-center mx-auto mb-5 border border-gray-100">
                <span class="material-symbols-rounded text-[32px] text-apeiron-ink">lock_open</span>
            </div>
            <h3 class="text-xl font-bold text-apeiron-ink mb-2">Open Cashier Shift</h3>
            <p class="text-sm text-gray-500 mb-8">Select an active terminal, count physical opening cash in the drawer, and verify your PIN.</p>

            <form wire:submit.prevent="openShift" class="text-left space-y-5">
                {{-- Terminal / Device Selection --}}
                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-gray-600 mb-2">Cashier Terminal / Device</label>
                    <div class="relative">
                        <select wire:model="selectedDeviceId" class="w-full px-4 py-3 bg-gray-50 border border-gray-200 rounded-xl focus:ring-1 focus:ring-gray-900 focus:border-apeiron-ink transition-all text-xs font-bold text-apeiron-ink outline-none">
                            <option value="">-- Select Terminal Device --</option>
                            @foreach($devices as $device)
                                <option value="{{ $device->id }}">
                                    {{ $device->device_name }} ({{ $device->device_code }})
                                </option>
                            @endforeach
                        </select>
                    </div>
                    @error('deviceId') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                </div>

                {{-- Opening Balance --}}
                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-gray-600 mb-2">Opening Drawer Cash</label>
                    <div class="relative">
                        <span class="absolute inset-y-0 left-0 flex items-center pl-4 font-bold text-gray-400">Rp</span>
                        <input type="number" wire:model="opening_balance" required min="0" class="w-full pl-12 pr-4 py-3 bg-gray-50 border border-gray-200 rounded-xl focus:ring-1 focus:ring-gray-900 focus:border-apeiron-ink transition-all font-mono font-bold text-base outline-none" placeholder="0">
                    </div>
                    @error('opening_balance') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                </div>

                {{-- Cashier PIN --}}
                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-gray-600 mb-2">Cashier PIN (6 Digits)</label>
                    <div class="relative">
                        <input type="password" wire:model="cashierPin" maxlength="6" class="w-full px-4 py-3 bg-gray-50 border border-gray-200 rounded-xl focus:ring-1 focus:ring-gray-900 focus:border-apeiron-ink transition-all font-mono font-bold text-base tracking-widest text-center outline-none" placeholder="••••••">
                    </div>
                    @error('cashierPin') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                </div>

                <button type="submit" wire:loading.attr="disabled" wire:target="openShift"
                    x-on:click="window.erpMotion?.pulseHighlight($el)"
                    class="w-full disabled:opacity-60 disabled:cursor-wait bg-apeiron-ink hover:bg-[#111111] text-white font-bold py-3.5 rounded-xl shadow-md active:scale-[0.98] transition-all flex justify-center items-center gap-2 focus:outline-none focus-visible:ring-2 focus-visible:ring-apeiron-ink">
                    <span wire:loading wire:target="openShift" class="h-4 w-4 animate-spin rounded-full border-2 border-white/30 border-t-white"></span>
                    <span>Start Shift Session</span>
                </button>
            </form>
        </div>
    @else
        {{-- Active Shift Dashboard --}}
        <div class="space-y-6">

            {{-- Operational Context Header --}}
            <div class="bg-white rounded-3xl p-6 border border-gray-200 shadow-sm flex flex-col md:flex-row items-start md:items-center justify-between gap-4">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 rounded-2xl bg-emerald-50 border border-emerald-100 flex items-center justify-center text-emerald-600">
                        <span class="material-symbols-rounded text-[28px]">point_of_sale</span>
                    </div>
                    <div>
                        <div class="flex items-center gap-2.5">
                            <h3 class="text-lg font-bold text-apeiron-ink">Shift #{{ $activeShift->id }}</h3>
                            @if($activeShift->status === 'handover_pending')
                                <x-ui.badge variant="warning">
                                    <span class="w-1.5 h-1.5 rounded-full bg-amber-500 mr-1.5"></span>
                                    Handover Pending
                                </x-ui.badge>
                            @else
                                <x-ui.badge variant="success">
                                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse mr-1.5"></span>
                                    Active
                                </x-ui.badge>
                            @endif
                        </div>
                        <p class="text-xs text-gray-500 mt-1">
                            Opened by <span class="font-bold text-gray-700">{{ $activeShift->user->name ?? 'Cashier' }}</span> 
                            at <span class="font-medium text-gray-700">{{ $activeShift->opened_at->format('d M Y, H:i') }}</span>
                            @if($activeShift->device)
                                &bull; Terminal: <span class="font-bold text-gray-700">{{ $activeShift->device->device_name }} ({{ $activeShift->device->device_code }})</span>
                            @endif
                        </p>
                    </div>
                </div>

                {{-- Action Buttons --}}
                <div class="flex flex-wrap items-center gap-2 w-full md:w-auto">
                    <button type="button" wire:click="$set('isMovementModalOpen', true)"
                        x-on:click="window.erpMotion?.pulseHighlight($el)"
                        class="px-3.5 py-2 rounded-xl border border-gray-200 bg-white text-xs font-bold text-apeiron-ink hover:bg-gray-50 active:scale-95 transition-all shadow-sm flex items-center gap-1.5">
                        <span class="material-symbols-rounded text-[18px]">account_balance_wallet</span> Cash In / Out
                    </button>
                    <button type="button" wire:click="$set('isHandoverModalOpen', true)"
                        x-on:click="window.erpMotion?.pulseHighlight($el)"
                        class="px-3.5 py-2 rounded-xl border border-gray-200 bg-white text-xs font-bold text-apeiron-ink hover:bg-gray-50 active:scale-95 transition-all shadow-sm flex items-center gap-1.5">
                        <span class="material-symbols-rounded text-[18px]">swap_horiz</span> Handover
                    </button>
                    <button type="button" x-on:click="openCloseModal = true; window.erpMotion?.pulseHighlight($el)"
                        class="px-4 py-2 rounded-xl bg-apeiron-ink text-white text-xs font-bold hover:bg-[#111111] active:scale-95 transition-all shadow-sm flex items-center gap-1.5">
                        <span class="material-symbols-rounded text-[18px]">lock</span> Close Shift
                    </button>
                    @if(auth()->user()?->role?->is_admin || in_array(auth()->user()?->role?->name, ['Owner', 'Manager', 'Supervisor'], true))
                        <button type="button" wire:click="$set('isForceCloseModalOpen', true)"
                            x-on:click="window.erpMotion?.pulseHighlight($el)"
                            class="px-3 py-2 rounded-xl border border-rose-200 bg-rose-50 text-rose-700 text-xs font-bold hover:bg-rose-100 active:scale-95 transition-all flex items-center gap-1">
                            <span class="material-symbols-rounded text-[18px]">gavel</span> Force Close
                        </button>
                    @endif
                </div>
            </div>

            {{-- Pending Handover Panel (If any) --}}
            @if($pendingHandover)
                <div class="bg-amber-50 border border-amber-200 rounded-2xl p-5 shadow-sm">
                    <div class="flex items-start gap-4">
                        <span class="material-symbols-rounded text-[26px] text-amber-600 shrink-0">pending_actions</span>
                        <div class="flex-1">
                            <h4 class="font-bold text-sm text-amber-900">Confirm Shift Handover</h4>
                            <p class="text-xs text-amber-800 mt-1">
                                Cashier <span class="font-bold">{{ $pendingHandover->fromUser?->name }}</span> is handing over shift to <span class="font-bold">{{ $pendingHandover->toUser?->name }}</span>.
                                Counted cash at handover: <span class="font-mono font-bold">Rp {{ number_format($pendingHandover->counted_cash_at_handover, 0, ',', '.') }}</span>
                                @if($pendingHandover->cash_discrepancy != 0)
                                    (Cash discrepancy: <span class="font-mono font-bold text-rose-600">Rp {{ number_format($pendingHandover->cash_discrepancy, 0, ',', '.') }}</span>)
                                @endif
                            </p>
                            @if($pendingHandover->notes)
                                <p class="text-xs text-amber-700 mt-1 italic">Notes: {{ $pendingHandover->notes }}</p>
                            @endif

                            <div class="mt-3 flex flex-wrap items-center gap-2.5">
                                <div>
                                    <input type="password" wire:model="cashierPin" placeholder="Recipient Cashier PIN" maxlength="6"
                                        class="px-3 py-1.5 bg-white border border-amber-300 rounded-xl text-xs font-mono font-bold text-center tracking-wider focus:ring-1 focus:ring-amber-500 outline-none">
                                </div>
                                @if($pendingHandover->cash_discrepancy != 0)
                                    <div>
                                        <input type="password" wire:model="supervisorPin" placeholder="Supervisor PIN" maxlength="6"
                                            class="px-3 py-1.5 bg-white border border-rose-300 rounded-xl text-xs font-mono font-bold text-center tracking-wider focus:ring-1 focus:ring-rose-500 outline-none">
                                    </div>
                                @endif
                                <button type="button" wire:click="acceptHandover({{ $pendingHandover->id }})"
                                    x-on:click="window.erpMotion?.pulseHighlight($el)"
                                    class="px-3.5 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold rounded-xl shadow-sm active:scale-95 transition-all">
                                    Accept Handover
                                </button>
                                <button type="button" wire:click="cancelHandover({{ $pendingHandover->id }})"
                                    class="px-3 py-1.5 border border-gray-300 bg-white hover:bg-gray-50 text-gray-700 text-xs font-semibold rounded-xl active:scale-95 transition-all">
                                    Cancel
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            @endif

            {{-- KPI Summary Cards --}}
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                <div class="bg-white rounded-2xl p-5 border border-gray-200 shadow-sm" data-gsap="kpi-card">
                    <span class="text-[11px] font-bold uppercase tracking-wider text-gray-400 block mb-1.5">Opening Balance</span>
                    <p class="text-xl font-black text-apeiron-ink font-mono tabular-nums">Rp {{ number_format($activeShift->opening_balance, 0, ',', '.') }}</p>
                    <span class="text-xs text-gray-400 mt-1 block">Drawer cash balance when shift opened</span>
                </div>

                <div class="bg-white rounded-2xl p-5 border border-gray-200 shadow-sm" data-gsap="kpi-card">
                    <span class="text-[11px] font-bold uppercase tracking-wider text-emerald-600 block mb-1.5">Cash Sales Revenue</span>
                    <p class="text-xl font-black text-emerald-600 font-mono tabular-nums">+ Rp {{ number_format($cashSales, 0, ',', '.') }}</p>
                    <span class="text-xs text-gray-400 mt-1 block">Total cash transactions during this shift</span>
                </div>

                @php
                    $totalCashIn = $cashMovements->where('type.value', 'cash_in')->sum('amount');
                    $totalCashOut = $cashMovements->where('type.value', 'cash_out')->sum('amount');
                @endphp
                <div class="bg-white rounded-2xl p-5 border border-gray-200 shadow-sm" data-gsap="kpi-card">
                    <span class="text-[11px] font-bold uppercase tracking-wider text-amber-600 block mb-1.5">Drawer Cash Movements</span>
                    <p class="text-base font-bold text-apeiron-ink font-mono tabular-nums">
                        <span class="text-emerald-600">+{{ number_format($totalCashIn, 0, ',', '.') }}</span> / 
                        <span class="text-rose-600">-{{ number_format($totalCashOut, 0, ',', '.') }}</span>
                    </p>
                    <span class="text-xs text-gray-400 mt-1 block">Cash-In vs Cash-Out</span>
                </div>

                <div class="bg-white rounded-2xl p-5 border border-gray-200 shadow-sm bg-gradient-to-br from-white to-gray-50" data-gsap="kpi-card">
                    <span class="text-[11px] font-bold uppercase tracking-wider text-apeiron-ink block mb-1.5">Expected Drawer Cash</span>
                    <p class="text-2xl font-black text-apeiron-ink font-mono tabular-nums">Rp {{ number_format($expectedCash, 0, ',', '.') }}</p>
                    <span class="text-xs text-gray-400 mt-1 block">Expected cash balance at closing</span>
                </div>
            </div>

            {{-- Drawer Cash Movement History --}}
            <div class="bg-white rounded-2xl p-5 border border-gray-200 shadow-sm">
                <div class="flex items-center justify-between mb-4">
                    <div>
                        <h4 class="font-bold text-sm text-apeiron-ink">Drawer Cash Movement History</h4>
                        <p class="text-xs text-gray-500 mt-0.5">Record of cash in, cash out, and cash drops for the active shift.</p>
                    </div>
                    <button type="button" wire:click="$set('isMovementModalOpen', true)"
                        x-on:click="window.erpMotion?.pulseHighlight($el)"
                        class="px-3 py-1.5 rounded-xl bg-gray-100 hover:bg-gray-200 text-xs font-bold text-apeiron-ink transition-all flex items-center gap-1">
                        <span class="material-symbols-rounded text-[16px]">add</span> Add Movement
                    </button>
                </div>

                <div class="overflow-x-auto">
                    <table class="table-standard w-full min-w-full text-left text-xs">
                        <thead>
                            <tr class="border-b border-gray-100 text-gray-400 uppercase tracking-wider font-semibold">
                                <th class="py-2.5 px-3">Time</th>
                                <th class="py-2.5 px-3">Type</th>
                                <th class="py-2.5 px-3">Category</th>
                                <th class="py-2.5 px-3 text-right">Amount</th>
                                <th class="py-2.5 px-3">Reason / Note</th>
                                <th class="py-2.5 px-3">Operator</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-50">
                            @forelse($cashMovements as $move)
                                <tr class="hover:bg-gray-50/50 transition-colors">
                                    <td class="py-2.5 px-3 text-gray-500">{{ $move->created_at->format('H:i:s') }}</td>
                                    <td class="py-2.5 px-3">
                                        @if($move->type->value === 'cash_in')
                                            <x-ui.badge variant="success">Cash In</x-ui.badge>
                                        @else
                                            <x-ui.badge variant="destructive">Cash Out</x-ui.badge>
                                        @endif
                                    </td>
                                    <td class="py-2.5 px-3 font-medium text-gray-700">{{ $move->category->value }}</td>
                                    <td class="py-2.5 px-3 font-mono font-bold text-right tabular-nums {{ $move->type->value === 'cash_in' ? 'text-emerald-600' : 'text-rose-600' }}">
                                        {{ $move->type->value === 'cash_in' ? '+' : '-' }} Rp {{ number_format($move->amount, 0, ',', '.') }}
                                    </td>
                                    <td class="py-2.5 px-3 text-gray-600 max-w-xs truncate">{{ $move->reason }}</td>
                                    <td class="py-2.5 px-3 text-gray-500">{{ $move->createdBy->name ?? '-' }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="py-8 text-center text-gray-400">No drawer cash movements recorded in this shift.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- Shift Handover History --}}
            @if($handovers->isNotEmpty())
                <div class="bg-white rounded-2xl p-5 border border-gray-200 shadow-sm">
                    <h4 class="font-bold text-sm text-apeiron-ink mb-1">Shift Handover History</h4>
                    <p class="text-xs text-gray-500 mb-4">List of operational cashier transfers during this shift.</p>

                    <div class="overflow-x-auto">
                        <table class="table-standard w-full min-w-full text-left text-xs">
                            <thead>
                                <tr class="border-b border-gray-100 text-gray-400 uppercase tracking-wider font-semibold">
                                    <th class="py-2.5 px-3">Time</th>
                                    <th class="py-2.5 px-3">From Cashier</th>
                                    <th class="py-2.5 px-3">To Cashier</th>
                                    <th class="py-2.5 px-3 text-right">Counted Cash</th>
                                    <th class="py-2.5 px-3 text-right">Discrepancy</th>
                                    <th class="py-2.5 px-3 text-center">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-50">
                                @foreach($handovers as $ho)
                                    <tr>
                                        <td class="py-2.5 px-3 text-gray-500">{{ $ho->initiated_at->format('d/m H:i') }}</td>
                                        <td class="py-2.5 px-3 font-medium text-gray-700">{{ $ho->fromUser?->name }}</td>
                                        <td class="py-2.5 px-3 font-medium text-gray-700">{{ $ho->toUser?->name }}</td>
                                        <td class="py-2.5 px-3 font-mono font-bold text-right tabular-nums">Rp {{ number_format($ho->counted_cash_at_handover, 0, ',', '.') }}</td>
                                        <td class="py-2.5 px-3 font-mono text-right tabular-nums {{ $ho->cash_discrepancy != 0 ? 'text-rose-600 font-bold' : 'text-gray-500' }}">
                                            Rp {{ number_format($ho->cash_discrepancy, 0, ',', '.') }}
                                        </td>
                                        <td class="py-2.5 px-3 text-center">
                                            @if($ho->status === 'accepted')
                                                <x-ui.badge variant="success">Accepted</x-ui.badge>
                                            @elseif($ho->status === 'cancelled')
                                                <x-ui.badge variant="secondary">Cancelled</x-ui.badge>
                                            @else
                                                <x-ui.badge variant="warning">Pending</x-ui.badge>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif
        </div>

        {{-- Record Cash Movement Modal (Cash-In / Cash-Out / Cash Drop) --}}
        <x-ui.modal wire:model="isMovementModalOpen" maxWidth="md">
            <x-slot:header>
                <div class="flex items-center gap-2">
                    <span class="material-symbols-rounded text-apeiron-ink text-[22px]">account_balance_wallet</span>
                    <span class="text-base font-bold">Record Cash Movement</span>
                </div>
            </x-slot:header>

            <form id="cash-movement-form" wire:submit.prevent="recordCashMovement" class="space-y-4">
                {{-- Movement Type --}}
                <div class="grid grid-cols-2 gap-2 p-1 bg-gray-100 rounded-xl">
                    <label class="flex items-center justify-center py-2 text-xs font-bold rounded-lg cursor-pointer transition-all {{ $movementType === 'cash_in' ? 'bg-white text-apeiron-ink shadow-sm' : 'text-gray-500' }}">
                        <input type="radio" wire:model.live="movementType" value="cash_in" class="sr-only"> Cash In
                    </label>
                    <label class="flex items-center justify-center py-2 text-xs font-bold rounded-lg cursor-pointer transition-all {{ $movementType === 'cash_out' ? 'bg-white text-apeiron-ink shadow-sm' : 'text-gray-500' }}">
                        <input type="radio" wire:model.live="movementType" value="cash_out" class="sr-only"> Cash Out
                    </label>
                </div>

                {{-- Movement Category --}}
                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-gray-600 mb-1">Category</label>
                    <select wire:model="movementCategory" class="w-full px-3.5 py-2.5 bg-gray-50 border border-gray-200 rounded-xl text-xs font-bold text-apeiron-ink focus:bg-white focus:ring-1 focus:ring-gray-900 outline-none">
                        @if($movementType === 'cash_in')
                            <option value="restock_change">Restock Change (Float / Small Bills)</option>
                            <option value="correction">Cash In Correction</option>
                        @else
                            <option value="petty_expense">Petty Cash Expense</option>
                            <option value="bank_deposit">Cash Drop / Bank Deposit</option>
                            <option value="owner_withdrawal">Owner Withdrawal</option>
                            <option value="correction">Cash Out Correction</option>
                        @endif
                    </select>
                </div>

                {{-- Amount --}}
                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-gray-600 mb-1">Amount (Rp)</label>
                    <input type="number" wire:model="movementAmount" required min="1" class="w-full px-3.5 py-2.5 bg-gray-50 border border-gray-200 rounded-xl font-mono font-bold text-sm text-apeiron-ink focus:bg-white focus:ring-1 focus:ring-gray-900 outline-none" placeholder="0">
                    @error('movementAmount') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                </div>

                {{-- Reason / Description --}}
                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-gray-600 mb-1">Reason / Note</label>
                    <textarea wire:model="movementReason" required rows="2" class="w-full px-3.5 py-2.5 bg-gray-50 border border-gray-200 rounded-xl text-xs text-apeiron-ink focus:bg-white focus:ring-1 focus:ring-gray-900 outline-none resize-none" placeholder="e.g. Added small change bills..."></textarea>
                    @error('movementReason') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                </div>

                {{-- Supervisor PIN if Cash-Out --}}
                @if($movementType === 'cash_out')
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wider text-rose-600 mb-1">Supervisor PIN (Cash Out Authorization)</label>
                        <input type="password" wire:model="movementSupervisorPin" maxlength="6" class="w-full px-3.5 py-2.5 bg-rose-50/40 border border-rose-200 rounded-xl text-center font-mono font-bold text-sm tracking-widest text-rose-900 focus:bg-white focus:ring-1 focus:ring-rose-500 outline-none" placeholder="••••••">
                        @error('movementSupervisorPin') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                    </div>
                @endif
            </form>

            <x-slot:footer>
                <div class="flex items-center justify-end gap-2 w-full">
                    <button type="button" wire:click="$set('isMovementModalOpen', false)" class="px-4 py-2 rounded-xl border border-gray-200 text-xs font-semibold text-gray-600 hover:bg-gray-100 transition-colors">
                        Cancel
                    </button>
                    <button type="submit" form="cash-movement-form"
                        x-on:click="window.erpMotion?.pulseHighlight($el)"
                        class="px-4 py-2 rounded-xl bg-apeiron-ink text-white text-xs font-bold hover:bg-[#111111] shadow-sm active:scale-95 transition-all">
                        Save Movement
                    </button>
                </div>
            </x-slot:footer>
        </x-ui.modal>

        {{-- Shift Handover Modal --}}
        <x-ui.modal wire:model="isHandoverModalOpen" maxWidth="md">
            <x-slot:header>
                <div class="flex items-center gap-2">
                    <span class="material-symbols-rounded text-apeiron-ink text-[22px]">swap_horiz</span>
                    <span class="text-base font-bold">Initiate Shift Handover</span>
                </div>
            </x-slot:header>

            <form id="handover-form" wire:submit.prevent="initiateHandover" class="space-y-4">
                {{-- Select Recipient Cashier --}}
                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-gray-600 mb-1">Recipient Cashier</label>
                    <select wire:model="handoverToUserId" required class="w-full px-3.5 py-2.5 bg-gray-50 border border-gray-200 rounded-xl text-xs font-bold text-apeiron-ink focus:bg-white focus:ring-1 focus:ring-gray-900 outline-none">
                        <option value="">-- Select Recipient Cashier --</option>
                        @foreach($eligibleCashiers as $c)
                            <option value="{{ $c->id }}">{{ $c->name }} ({{ $c->role?->name ?? 'Cashier' }})</option>
                        @endforeach
                    </select>
                    @error('handoverToUserId') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                </div>

                {{-- Counted Cash at Handover --}}
                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-gray-600 mb-1">Counted Physical Cash (Rp)</label>
                    <input type="number" wire:model="handoverCountedCash" required min="0" class="w-full px-3.5 py-2.5 bg-gray-50 border border-gray-200 rounded-xl font-mono font-bold text-sm text-apeiron-ink focus:bg-white focus:ring-1 focus:ring-gray-900 outline-none" placeholder="0">
                    @error('handoverCountedCash') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                </div>

                {{-- Notes --}}
                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-gray-600 mb-1">Handover Notes (Optional)</label>
                    <textarea wire:model="handoverNotes" rows="2" class="w-full px-3.5 py-2.5 bg-gray-50 border border-gray-200 rounded-xl text-xs text-apeiron-ink focus:bg-white focus:ring-1 focus:ring-gray-900 outline-none resize-none" placeholder="Operational notes..."></textarea>
                </div>

                {{-- Transferring Cashier PIN --}}
                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-gray-600 mb-1">Transferring Cashier PIN</label>
                    <input type="password" wire:model="cashierPin" maxlength="6" class="w-full px-3.5 py-2.5 bg-gray-50 border border-gray-200 rounded-xl text-center font-mono font-bold text-sm tracking-widest focus:bg-white focus:ring-1 focus:ring-gray-900 outline-none" placeholder="••••••">
                    @error('cashierPin') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                </div>
            </form>

            <x-slot:footer>
                <div class="flex items-center justify-end gap-2 w-full">
                    <button type="button" wire:click="$set('isHandoverModalOpen', false)" class="px-4 py-2 rounded-xl border border-gray-200 text-xs font-semibold text-gray-600 hover:bg-gray-100 transition-colors">
                        Cancel
                    </button>
                    <button type="submit" form="handover-form"
                        x-on:click="window.erpMotion?.pulseHighlight($el)"
                        class="px-4 py-2 rounded-xl bg-apeiron-ink text-white text-xs font-bold hover:bg-[#111111] shadow-sm active:scale-95 transition-all">
                        Submit Handover
                    </button>
                </div>
            </x-slot:footer>
        </x-ui.modal>

        {{-- Normal Close Shift Modal (Alpine controlled) --}}
        <div x-show="openCloseModal" style="display: none;" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 backdrop-blur-sm p-4 animate-fade-in"
            @keydown.escape.window="openCloseModal = false">
            <div class="bg-white rounded-3xl p-6 md:p-8 border border-gray-200 shadow-2xl max-w-md w-full animate-slide-up-fade"
                @click.outside="openCloseModal = false">
                <div class="flex items-center justify-between pb-3 border-b border-gray-100 mb-4">
                    <h3 class="text-base font-bold text-apeiron-ink flex items-center gap-2">
                        <span class="material-symbols-rounded text-apeiron-ink">lock</span> Close Cashier Shift Session
                    </h3>
                    <button type="button" @click="openCloseModal = false" class="size-7 flex items-center justify-center rounded-lg text-gray-400 hover:bg-gray-100">
                        <span class="material-symbols-rounded text-[18px]">close</span>
                    </button>
                </div>

                <form wire:submit.prevent="closeShift" class="space-y-4">
                    {{-- Cash Reconciliation Live --}}
                    <div class="p-3.5 bg-gray-50 rounded-xl border border-gray-100 space-y-1.5 text-xs">
                        <div class="flex justify-between text-gray-500">
                            <span>Expected Drawer Cash:</span>
                            <span class="font-mono font-bold text-apeiron-ink">Rp {{ number_format($expectedCash, 0, ',', '.') }}</span>
                        </div>
                        <div class="flex justify-between text-gray-500">
                            <span>Counted Physical Cash:</span>
                            <span class="font-mono font-bold text-apeiron-ink" x-text="'Rp ' + (parseInt(countedCash) || 0).toLocaleString('id-ID')"></span>
                        </div>
                        <div class="flex justify-between pt-1.5 border-t border-gray-200 font-bold">
                            <span>Cash Discrepancy:</span>
                            <span class="font-mono" :class="discrepancy === 0 ? 'text-emerald-600' : 'text-rose-600'" x-text="(discrepancy >= 0 ? '+Rp ' : '-Rp ') + Math.abs(discrepancy).toLocaleString('id-ID')"></span>
                        </div>
                    </div>

                    {{-- Total Physical Cash --}}
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wider text-gray-600 mb-1">Counted Physical Cash in Drawer</label>
                        <input type="number" wire:model="closing_balance" x-model="countedCash" required min="0" class="w-full px-3.5 py-2.5 bg-gray-50 border border-gray-200 rounded-xl font-mono font-bold text-sm text-apeiron-ink focus:bg-white focus:ring-1 focus:ring-gray-900 outline-none" placeholder="0">
                    </div>

                    {{-- Active Cashier PIN --}}
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wider text-gray-600 mb-1">Cashier PIN</label>
                        <input type="password" wire:model="cashierPin" maxlength="6" class="w-full px-3.5 py-2.5 bg-gray-50 border border-gray-200 rounded-xl text-center font-mono font-bold text-sm tracking-widest focus:bg-white focus:ring-1 focus:ring-gray-900 outline-none" placeholder="••••••">
                        @error('cashierPin') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                    </div>

                    {{-- Discrepancy Notes --}}
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wider text-gray-600 mb-1">Notes / Discrepancy Explanation</label>
                        <textarea wire:model="notes" rows="2" class="w-full px-3.5 py-2.5 bg-gray-50 border border-gray-200 rounded-xl text-xs text-apeiron-ink focus:bg-white focus:ring-1 focus:ring-gray-900 outline-none resize-none" placeholder="Notes regarding discrepancy if any..."></textarea>
                    </div>

                    <div class="flex items-center justify-end gap-2 pt-2 border-t border-gray-100">
                        <button type="button" x-on:click="openCloseModal = false" class="px-4 py-2 rounded-xl border border-gray-200 text-xs font-semibold text-gray-600 hover:bg-gray-100 transition-colors">
                            Cancel
                        </button>
                        <button type="submit"
                            x-on:click="window.erpMotion?.pulseHighlight($el)"
                            class="px-4 py-2 rounded-xl bg-apeiron-ink text-white text-xs font-bold hover:bg-[#111111] shadow-sm active:scale-95 transition-all">
                            Confirm Close Shift
                        </button>
                    </div>
                </form>
            </div>
        </div>

        {{-- Force Close Shift Modal (Supervisor / Manager Only) --}}
        <x-ui.modal wire:model="isForceCloseModalOpen" maxWidth="md">
            <x-slot:header>
                <div class="flex items-center gap-2 text-rose-600">
                    <span class="material-symbols-rounded text-[22px]">warning</span>
                    <span class="text-base font-bold">Force Close Shift</span>
                </div>
            </x-slot:header>

            <form id="force-close-form" wire:submit.prevent="forceCloseShift" class="space-y-4">
                <p class="text-xs text-gray-600">
                    This action is reserved for Supervisors/Managers to forcibly close an abandoned or problematic shift.
                </p>

                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-gray-600 mb-1">Counted Physical Cash in Drawer</label>
                    <input type="number" wire:model="closing_balance" required min="0" class="w-full px-3.5 py-2.5 bg-gray-50 border border-gray-200 rounded-xl font-mono font-bold text-sm text-apeiron-ink focus:bg-white focus:ring-1 focus:ring-gray-900 outline-none" placeholder="0">
                </div>

                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-rose-600 mb-1">Force Close Reason (Required)</label>
                    <textarea wire:model="forceCloseReason" required rows="2" class="w-full px-3.5 py-2.5 bg-rose-50/30 border border-rose-200 rounded-xl text-xs text-apeiron-ink focus:bg-white focus:ring-1 focus:ring-rose-500 outline-none resize-none" placeholder="e.g. Cashier suddenly taken ill, shift handed over to supervisor..."></textarea>
                    @error('forceCloseReason') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-rose-600 mb-1">Supervisor PIN (Authorization)</label>
                    <input type="password" wire:model="forceCloseSupervisorPin" maxlength="6" required class="w-full px-3.5 py-2.5 bg-rose-50/50 border border-rose-200 rounded-xl text-center font-mono font-bold text-sm tracking-widest text-rose-900 focus:bg-white focus:ring-1 focus:ring-rose-500 outline-none" placeholder="••••••">
                    @error('forceCloseSupervisorPin') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                </div>
            </form>

            <x-slot:footer>
                <div class="flex items-center justify-end gap-2 w-full">
                    <button type="button" wire:click="$set('isForceCloseModalOpen', false)" class="px-4 py-2 rounded-xl border border-gray-200 text-xs font-semibold text-gray-600 hover:bg-gray-100 transition-colors">
                        Cancel
                    </button>
                    <button type="submit" form="force-close-form"
                        x-on:click="window.erpMotion?.pulseHighlight($el)"
                        class="px-4 py-2 rounded-xl bg-rose-600 hover:bg-rose-700 text-white text-xs font-bold shadow-sm active:scale-95 transition-all">
                        Execute Force Close
                    </button>
                </div>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
