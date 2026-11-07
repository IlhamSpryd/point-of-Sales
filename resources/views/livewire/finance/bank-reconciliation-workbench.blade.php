<div class="space-y-6">
    @include('finance.partials.sub-nav')

    <!-- Flash Notifications -->
    @if (session()->has('success'))
        <div class="p-4 rounded-xl bg-emerald-500/10 border border-emerald-500/20 text-emerald-600 dark:text-emerald-400 text-sm flex items-center justify-between">
            <div class="flex items-center gap-2">
                <span class="material-symbols-rounded text-lg">check_circle</span>
                <span>{{ session('success') }}</span>
            </div>
            <button type="button" @click="$el.parentElement.remove()" class="text-emerald-500 hover:text-emerald-700">
                <span class="material-symbols-rounded text-sm">close</span>
            </button>
        </div>
    @endif

    @if (session()->has('error'))
        <div class="p-4 rounded-xl bg-rose-500/10 border border-rose-500/20 text-rose-600 dark:text-rose-400 text-sm flex items-center justify-between">
            <div class="flex items-center gap-2">
                <span class="material-symbols-rounded text-lg">error</span>
                <span>{{ session('error') }}</span>
            </div>
            <button type="button" @click="$el.parentElement.remove()" class="text-rose-500 hover:text-rose-700">
                <span class="material-symbols-rounded text-sm">close</span>
            </button>
        </div>
    @endif

    <!-- Header Action Bar -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-xl font-bold text-zinc-950 dark:text-white tracking-tight">Bank Reconciliation</h1>
            <p class="text-xs text-zinc-500">Match bank statement transactions against General Ledger cash & bank entries.</p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <select wire:model.live="selectedAccountId"
                    class="px-3 py-1.5 text-xs rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 text-zinc-900 dark:text-zinc-100 font-medium focus:outline-none">
                <option value="">-- Select Cash/Bank Account --</option>
                @foreach ($accounts as $acc)
                    <option value="{{ $acc->id }}">[{{ $acc->account_code }}] {{ $acc->account_name }} ({{ $acc->bank_name ?: 'Cash' }})</option>
                @endforeach
            </select>

            <button type="button"
                    wire:click="openStartSessionModal"
                    class="inline-flex items-center gap-1.5 px-4 py-2 text-xs font-semibold text-white bg-zinc-950 hover:bg-zinc-800 dark:bg-white dark:text-zinc-950 dark:hover:bg-zinc-200 rounded-xl transition-colors shadow-xs">
                <span class="material-symbols-rounded text-base">add</span>
                <span>Start New Session</span>
            </button>
        </div>
    </div>

    @if ($session)
        <!-- Reconciliation Summary Card -->
        <div class="card-surface p-5 rounded-2xl border border-border space-y-4 shadow-xs">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 pb-4 border-b border-zinc-200 dark:border-zinc-800">
                <div>
                    <div class="flex items-center gap-2">
                        <span class="font-sans font-bold text-zinc-950 dark:text-white text-sm">{{ $session->session_number }}</span>
                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold {{ $session->status->value === 'COMPLETED' ? 'bg-emerald-500/10 text-emerald-600' : 'bg-amber-500/10 text-amber-600' }}">
                            {{ $session->status->value }}
                        </span>
                    </div>
                    <p class="text-xs text-zinc-500">As of: {{ $session->statement_end_date->format('d M Y') }} • Account: {{ $session->account?->account_name }}</p>
                </div>

                <div class="flex items-center gap-2">
                    @if ($session->status->value === 'IN_PROGRESS')
                        <button type="button"
                                wire:click="runAutoMatch"
                                class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold text-zinc-900 dark:text-zinc-100 bg-zinc-100 hover:bg-zinc-200 dark:bg-zinc-800 dark:hover:bg-zinc-700 rounded-xl transition-colors">
                            <span class="material-symbols-rounded text-sm">auto_fix_high</span>
                            <span>Auto-Match</span>
                        </button>
                        <button type="button"
                                wire:click="completeSession"
                                @if(bccomp((string) $difference, '0.00', 2) !== 0) disabled @endif
                                class="inline-flex items-center gap-1.5 px-4 py-1.5 text-xs font-semibold text-white bg-emerald-600 hover:bg-emerald-700 rounded-xl transition-colors shadow-xs disabled:opacity-40 disabled:cursor-not-allowed">
                            <span class="material-symbols-rounded text-sm">check_circle</span>
                            <span>Complete Reconciliation</span>
                        </button>
                    @endif
                </div>
            </div>

            <!-- Balances Metrics Bar -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 font-sans text-xs">
                <div class="p-3 rounded-xl bg-zinc-50 dark:bg-zinc-900/60 border border-zinc-200 dark:border-zinc-800">
                    <span class="text-[10px] text-zinc-400 block uppercase">Statement Ending Balance</span>
                    <span class="text-sm font-bold text-zinc-950 dark:text-white">Rp {{ number_format((float) $session->statement_ending_balance, 2) }}</span>
                </div>
                <div class="p-3 rounded-xl bg-zinc-50 dark:bg-zinc-900/60 border border-zinc-200 dark:border-zinc-800">
                    <span class="text-[10px] text-zinc-400 block uppercase">General Ledger Ending Balance</span>
                    <span class="text-sm font-bold text-zinc-950 dark:text-white">Rp {{ number_format((float) $session->book_ending_balance, 2) }}</span>
                </div>
                <div class="p-3 rounded-xl border {{ bccomp((string) $difference, '0.00', 2) === 0 ? 'bg-emerald-500/10 border-emerald-500/20 text-emerald-600 dark:text-emerald-400' : 'bg-rose-500/10 border-rose-500/20 text-rose-600 dark:text-rose-400' }}">
                    <span class="text-[10px] block uppercase opacity-80">Unreconciled Difference</span>
                    <span class="text-sm font-bold">Rp {{ number_format((float) $difference, 2) }}</span>
                </div>
            </div>
        </div>

        @if ($session->status->value === 'IN_PROGRESS')
            <!-- Action Toolbar for Manual Matching -->
            <div class="flex items-center justify-between p-3 rounded-xl bg-zinc-100 dark:bg-zinc-800/80 text-xs">
                <div class="flex items-center gap-3">
                    <span class="text-zinc-500">Manual Match:</span>
                    <span class="font-medium {{ $selectedStatementLineId ? 'text-emerald-600 dark:text-emerald-400 font-sans' : 'text-zinc-400' }}">
                        {{ $selectedStatementLineId ? '1 Statement Line Selected' : 'No statement line selected' }}
                    </span>
                    <span class="text-zinc-400">•</span>
                    <span class="font-medium {{ $selectedTransactionId ? 'text-emerald-600 dark:text-emerald-400 font-sans' : 'text-zinc-400' }}">
                        {{ $selectedTransactionId ? '1 Book Transaction Selected' : 'No book transaction selected' }}
                    </span>
                </div>

                <div class="flex items-center gap-2">
                    <button type="button"
                            wire:click="resetMatchSelection"
                            class="px-2.5 py-1 text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300">
                        Clear Selection
                    </button>
                    <button type="button"
                            wire:click="manualMatch"
                            @if(! $selectedStatementLineId || ! $selectedTransactionId) disabled @endif
                            class="inline-flex items-center gap-1.5 px-3.5 py-1.5 font-semibold text-white bg-zinc-950 hover:bg-zinc-800 dark:bg-white dark:text-zinc-950 dark:hover:bg-zinc-200 rounded-lg transition-colors disabled:opacity-40 disabled:cursor-not-allowed">
                        <span class="material-symbols-rounded text-sm">link</span>
                        <span>Match Selected</span>
                    </button>
                </div>
            </div>

            <!-- Two-Column Workbench (Statement Lines vs Cash/Bank Transactions) -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <!-- Left: Bank Statement Lines -->
                <div class="card-surface rounded-2xl border border-border overflow-hidden shadow-xs">
                    <div class="p-3 bg-zinc-50 dark:bg-zinc-900/60 border-b border-zinc-200 dark:border-zinc-800 flex items-center justify-between">
                        <h4 class="text-xs font-bold text-zinc-900 dark:text-zinc-100 flex items-center gap-1.5">
                            <span class="material-symbols-rounded text-sm text-blue-500">account_balance</span>
                            <span>Bank Statement Lines (Unreconciled: {{ $unreconciledStatementLines->count() }})</span>
                        </h4>
                    </div>

                    <div class="max-h-96 overflow-y-auto divide-y divide-zinc-100 dark:divide-zinc-800/60">
                        @forelse ($unreconciledStatementLines as $line)
                            <label class="p-3 flex items-start gap-3 hover:bg-zinc-50/60 dark:hover:bg-zinc-800/30 cursor-pointer transition-colors {{ $selectedStatementLineId === $line->id ? 'bg-blue-50/40 dark:bg-blue-900/20' : '' }}">
                                <input type="radio"
                                       name="statement_selection"
                                       value="{{ $line->id }}"
                                       wire:model.live="selectedStatementLineId"
                                       class="mt-0.5 text-zinc-900 focus:ring-zinc-900">
                                <div class="flex-1 min-w-0 text-xs">
                                    @php
                                        $isCredit = bccomp((string) $line->credit_amount, '0.00', 2) > 0;
                                        $lineAmt = $isCredit ? (float) $line->credit_amount : (float) $line->debit_amount;
                                    @endphp
                                    <div class="flex items-center justify-between font-sans">
                                        <span class="text-zinc-500 text-[11px]">{{ $line->value_date ? \Carbon\Carbon::parse($line->value_date)->format('d M Y') : '-' }}</span>
                                        <span class="font-bold {{ $isCredit ? 'text-emerald-600' : 'text-rose-600' }}">
                                            Rp {{ number_format($lineAmt, 2) }} ({{ $isCredit ? 'CR' : 'DB' }})
                                        </span>
                                    </div>
                                    <p class="text-zinc-800 dark:text-zinc-200 font-medium truncate mt-0.5">{{ $line->description ?: '-' }}</p>
                                    @if($line->external_reference)
                                        <span class="text-[10px] text-zinc-400 font-sans">Ref: {{ $line->external_reference }}</span>
                                    @endif
                                </div>
                            </label>
                        @empty
                            <div class="p-8 text-center text-zinc-400 text-xs">
                                No unreconciled bank statement lines found.
                            </div>
                        @endforelse
                    </div>
                </div>

                <!-- Right: Cash/Bank Journal Transactions -->
                <div class="card-surface rounded-2xl border border-border overflow-hidden shadow-xs">
                    <div class="p-3 bg-zinc-50 dark:bg-zinc-900/60 border-b border-zinc-200 dark:border-zinc-800 flex items-center justify-between">
                        <h4 class="text-xs font-bold text-zinc-900 dark:text-zinc-100 flex items-center gap-1.5">
                            <span class="material-symbols-rounded text-sm text-purple-500">menu_book</span>
                            <span>General Ledger Transactions (Unreconciled: {{ $unreconciledTransactions->count() }})</span>
                        </h4>
                    </div>

                    <div class="max-h-96 overflow-y-auto divide-y divide-zinc-100 dark:divide-zinc-800/60">
                        @forelse ($unreconciledTransactions as $txn)
                            <label class="p-3 flex items-start gap-3 hover:bg-zinc-50/60 dark:hover:bg-zinc-800/30 cursor-pointer transition-colors {{ $selectedTransactionId === $txn->id ? 'bg-purple-50/40 dark:bg-purple-900/20' : '' }}">
                                <input type="radio"
                                       name="transaction_selection"
                                       value="{{ $txn->id }}"
                                       wire:model.live="selectedTransactionId"
                                       class="mt-0.5 text-zinc-900 focus:ring-zinc-900">
                                <div class="flex-1 min-w-0 text-xs">
                                    <div class="flex items-center justify-between font-sans">
                                        <span class="text-zinc-500 text-[11px]">{{ \Carbon\Carbon::parse($txn->transaction_date)->format('d M Y') }}</span>
                                        <span class="font-bold {{ in_array($txn->transaction_type->value, ['RECEIPT', 'TRANSFER_IN', 'POS_SHIFT_DEPOSIT', 'PG_SETTLEMENT', 'BANK_INTEREST'], true) ? 'text-emerald-600' : 'text-rose-600' }}">
                                            Rp {{ number_format((float) $txn->amount, 2) }} ({{ $txn->transaction_type->value }})
                                        </span>
                                    </div>
                                    <p class="text-zinc-800 dark:text-zinc-200 font-medium truncate mt-0.5">{{ $txn->description ?: $txn->reference_number }}</p>
                                    <span class="text-[10px] text-zinc-400 font-sans">{{ $txn->transaction_number }}</span>
                                </div>
                            </label>
                        @empty
                            <div class="p-8 text-center text-zinc-400 text-xs">
                                No unreconciled cash/bank transactions found.
                            </div>
                        @endforelse
                    </div>
                </div>
            </div>
        @endif

        <!-- Matched Pairs List -->
        <div class="card-surface rounded-2xl border border-border overflow-hidden shadow-xs">
            <div class="p-4 border-b border-zinc-200 dark:border-zinc-800 flex items-center justify-between">
                <h4 class="text-xs font-bold text-zinc-900 dark:text-zinc-100 flex items-center gap-2">
                    <span class="material-symbols-rounded text-base text-emerald-500">check_circle</span>
                    <span>Reconciled Transactions ({{ $matches->count() }} Matched)</span>
                </h4>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead class="bg-zinc-50 dark:bg-zinc-900/60 text-zinc-500 font-semibold border-b border-zinc-200 dark:border-zinc-800">
                        <tr>
                            <th class="px-4 py-2.5">Matched Date</th>
                            <th class="px-4 py-2.5">Statement Line</th>
                            <th class="px-4 py-2.5">GL Transaction</th>
                            <th class="px-4 py-2.5 text-right">Matched Amount</th>
                            <th class="px-4 py-2.5 text-center">Match Type</th>
                            <th class="px-4 py-2.5 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800/60">
                        @forelse ($matches as $match)
                            <tr class="hover:bg-zinc-50/60 dark:hover:bg-zinc-800/30">
                                <td class="px-4 py-2.5 font-sans text-zinc-500">{{ $match->created_at->format('d M Y H:i') }}</td>
                                <td class="px-4 py-2.5">
                                    <span class="font-medium text-zinc-900 dark:text-zinc-100 block">{{ $match->statementLine?->description ?: '-' }}</span>
                                    <span class="font-sans text-[10px] text-zinc-400">{{ $match->statementLine?->value_date ? \Carbon\Carbon::parse($match->statementLine->value_date)->format('d M Y') : '-' }}</span>
                                </td>
                                <td class="px-4 py-2.5">
                                    <span class="font-medium text-zinc-900 dark:text-zinc-100 block">{{ $match->transaction?->description ?: $match->transaction?->reference_number }}</span>
                                    <span class="font-sans text-[10px] text-zinc-400">{{ $match->transaction?->transaction_number }}</span>
                                </td>
                                <td class="px-4 py-2.5 text-right font-sans font-bold text-emerald-600 dark:text-emerald-400">
                                    Rp {{ number_format((float) $match->matched_amount, 2) }}
                                </td>
                                <td class="px-4 py-2.5 text-center">
                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-semibold {{ $match->match_type === 'AUTO' ? 'bg-blue-500/10 text-blue-600' : 'bg-purple-500/10 text-purple-600' }}">
                                        {{ $match->match_type }}
                                    </span>
                                </td>
                                <td class="px-4 py-2.5 text-right">
                                    @if ($session->status->value === 'IN_PROGRESS')
                                        <button type="button"
                                                wire:click="unmatch({{ $match->id }})"
                                                class="p-1 rounded text-zinc-400 hover:text-rose-500 transition-colors"
                                                title="Unmatch">
                                            <span class="material-symbols-rounded text-sm">link_off</span>
                                        </button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-4 py-8 text-center text-zinc-400 text-xs">
                                    No transactions matched in this session yet.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @else
        <div class="card-surface p-12 rounded-2xl border border-border text-center space-y-3 shadow-xs">
            <span class="material-symbols-rounded text-4xl text-zinc-400">account_balance</span>
            <h3 class="text-base font-bold text-zinc-950 dark:text-white">No Active Reconciliation Session</h3>
            <p class="text-xs text-zinc-500 max-w-sm mx-auto">Select a cash/bank account and click "Start New Session" to begin matching statement lines.</p>
            <button type="button"
                    wire:click="openStartSessionModal"
                    class="inline-flex items-center gap-1.5 px-4 py-2 text-xs font-semibold text-white bg-zinc-950 hover:bg-zinc-800 dark:bg-white dark:text-zinc-950 dark:hover:bg-zinc-200 rounded-xl transition-colors shadow-xs">
                <span class="material-symbols-rounded text-base">add</span>
                <span>Start Reconciliation Session</span>
            </button>
        </div>
    @endif

    <!-- Start New Session Modal -->
    @if ($showStartSessionModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-xs">
            <div class="card-surface w-full max-w-md rounded-2xl border border-zinc-200 dark:border-zinc-800 p-6 shadow-2xl space-y-4"
                 @click.outside="$wire.set('showStartSessionModal', false)">
                
                <div class="flex items-center justify-between pb-3 border-b border-zinc-200 dark:border-zinc-800">
                    <h3 class="text-base font-bold text-zinc-950 dark:text-white">Start Bank Reconciliation Session</h3>
                    <button type="button" wire:click="$set('showStartSessionModal', false)" class="text-zinc-400 hover:text-zinc-600">
                        <span class="material-symbols-rounded text-lg">close</span>
                    </button>
                </div>

                <form wire:submit="startNewSession" class="space-y-4">
                    <div>
                        <label class="block text-xs font-semibold text-zinc-700 dark:text-zinc-300 mb-1">Cash / Bank Account</label>
                        <select wire:model="selectedAccountId"
                                class="w-full px-3 py-2 text-xs rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 text-zinc-900 dark:text-zinc-100">
                            @foreach ($accounts as $acc)
                                <option value="{{ $acc->id }}">[{{ $acc->account_code }}] {{ $acc->account_name }} ({{ $acc->bank_name ?: 'Cash' }})</option>
                            @endforeach
                        </select>
                        @error('selectedAccountId') <span class="text-[10px] text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-zinc-700 dark:text-zinc-300 mb-1">Statement Ending Date</label>
                        <input type="date"
                               wire:model="statementEndDate"
                               class="w-full px-3 py-2 text-xs rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 text-zinc-900 dark:text-zinc-100 font-sans">
                        @error('statementEndDate') <span class="text-[10px] text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-zinc-700 dark:text-zinc-300 mb-1">Statement Ending Balance</label>
                        <input type="number"
                               step="0.01"
                               wire:model="statementEndingBalance"
                               placeholder="e.g. 15000000.00"
                               class="w-full px-3 py-2 text-xs rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 text-zinc-900 dark:text-zinc-100 font-sans font-bold">
                        @error('statementEndingBalance') <span class="text-[10px] text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-zinc-700 dark:text-zinc-300 mb-1">Session Notes (Optional)</label>
                        <input type="text"
                               wire:model="sessionNotes"
                               placeholder="e.g. Monthly reconciliation for operational account..."
                               class="w-full px-3 py-2 text-xs rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 text-zinc-900 dark:text-zinc-100">
                    </div>

                    <div class="flex items-center justify-end gap-2 pt-3 border-t border-zinc-200 dark:border-zinc-800">
                        <button type="button"
                                wire:click="$set('showStartSessionModal', false)"
                                class="px-4 py-2 text-xs font-semibold text-zinc-600 hover:text-zinc-900 dark:text-zinc-300 dark:hover:text-white rounded-xl transition-colors">
                            Cancel
                        </button>
                        <button type="submit"
                                class="px-4 py-2 text-xs font-semibold text-white bg-zinc-950 hover:bg-zinc-800 dark:bg-white dark:text-zinc-950 dark:hover:bg-zinc-200 rounded-xl transition-colors shadow-xs">
                            Start Session
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>
