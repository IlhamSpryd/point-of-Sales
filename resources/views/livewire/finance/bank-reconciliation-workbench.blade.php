<div class="space-y-6">
    @include('finance.partials.sub-nav')

    <!-- Flash Notifications -->
    @if (session()->has('success'))
        <div class="p-4 rounded-xl bg-positive border border-positive/20 text-positive text-sm flex items-center justify-between">
            <div class="flex items-center gap-2">
                <span class="material-symbols-rounded text-lg">check_circle</span>
                <span>{{ session('success') }}</span>
            </div>
            <button type="button" @click="$el.parentElement.remove()" aria-label="Dismiss success alert" class="text-positive hover:text-positive">
                <span class="material-symbols-rounded text-sm" aria-hidden="true">close</span>
            </button>
        </div>
    @endif

    @if (session()->has('error'))
        <div class="p-4 rounded-xl bg-destructive border border-destructive/20 text-destructive text-sm flex items-center justify-between">
            <div class="flex items-center gap-2">
                <span class="material-symbols-rounded text-lg">error</span>
                <span>{{ session('error') }}</span>
            </div>
            <button type="button" @click="$el.parentElement.remove()" aria-label="Dismiss error alert" class="text-destructive hover:text-destructive">
                <span class="material-symbols-rounded text-sm" aria-hidden="true">close</span>
            </button>
        </div>
    @endif

    <!-- Header Action Bar -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-xl font-bold text-foreground tracking-tight">Bank Reconciliation</h1>
            <p class="text-xs text-muted-foreground">Match bank statement transactions against General Ledger cash & bank entries.</p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <select wire:model.live="selectedAccountId"
                    class="px-3 py-1.5 text-xs rounded-xl border border-border bg-card text-foreground font-medium focus:outline-none focus:ring-1 focus:ring-ring">
                <option value="">-- Select Cash/Bank Account --</option>
                @foreach ($accounts as $acc)
                    <option value="{{ $acc->id }}">[{{ $acc->account_code }}] {{ $acc->account_name }} ({{ $acc->bank_name ?: 'Cash' }})</option>
                @endforeach
            </select>

            <button type="button"
                    wire:click="openStartSessionModal"
                    class="inline-flex items-center gap-1.5 px-4 py-2 text-xs font-semibold text-primary-foreground bg-primary hover:bg-primary/90 rounded-xl transition-colors shadow-xs">
                <span class="material-symbols-rounded text-base">add</span>
                <span>Start New Session</span>
            </button>
        </div>
    </div>

    @if ($session)
        <!-- Reconciliation Summary Card -->
        <div class="card-surface p-5 rounded-2xl border border-border space-y-4 shadow-xs">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 pb-4 border-b border-border">
                <div>
                    <div class="flex items-center gap-2">
                        <span class="font-sans font-bold text-foreground text-sm">{{ $session->session_number }}</span>
                        <span class="inline-flex items-center gap-1.5 text-xs font-medium text-foreground">
                            <span class="w-1.5 h-1.5 rounded-full {{ $session->status->value === 'COMPLETED' ? 'bg-positive' : 'bg-warning' }}"></span>
                            {{ $session->status->value }}
                        </span>
                    </div>
                    <p class="text-xs text-muted-foreground">As of: {{ $session->statement_end_date->format('d M Y') }} • Account: {{ $session->account?->account_name }}</p>
                </div>

                <div class="flex items-center gap-2">
                    @if ($session->status->value === 'IN_PROGRESS')
                        <button type="button"
                                wire:click="runAutoMatch"
                                class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold text-foreground bg-secondary hover:bg-muted rounded-xl transition-colors">
                            <span class="material-symbols-rounded text-sm">auto_fix_high</span>
                            <span>Auto-Match</span>
                        </button>
                        <button type="button"
                                wire:click="completeSession"
                                @if(bccomp((string) $difference, '0.00', 2) !== 0) disabled @endif
                                class="inline-flex items-center gap-1.5 px-4 py-1.5 text-xs font-semibold text-on-positive bg-positive hover:bg-positive rounded-xl transition-colors shadow-xs disabled:opacity-40 disabled:cursor-not-allowed">
                            <span class="material-symbols-rounded text-sm">check_circle</span>
                            <span>Complete Reconciliation</span>
                        </button>
                    @endif
                </div>
            </div>

            <!-- Balances Metrics Bar -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 font-sans text-xs">
                <div class="p-3 rounded-xl bg-secondary /60 border border-border">
                    <span class="text-[10px] text-muted-foreground block uppercase">Statement Ending Balance</span>
                    <span class="text-sm font-bold text-foreground">Rp {{ number_format((float) $session->statement_ending_balance, 2) }}</span>
                </div>
                <div class="p-3 rounded-xl bg-secondary /60 border border-border">
                    <span class="text-[10px] text-muted-foreground block uppercase">General Ledger Ending Balance</span>
                    <span class="text-sm font-bold text-foreground">Rp {{ number_format((float) $session->book_ending_balance, 2) }}</span>
                </div>
                <div class="p-3 rounded-xl border {{ bccomp((string) $difference, '0.00', 2) === 0 ? 'bg-positive border-positive/20 text-positive ' : 'bg-destructive border-destructive/20 text-destructive ' }}">
                    <span class="text-[10px] block uppercase opacity-80">Unreconciled Difference</span>
                    <span class="text-sm font-bold">Rp {{ number_format((float) $difference, 2) }}</span>
                </div>
            </div>
        </div>

        @if ($session->status->value === 'IN_PROGRESS')
            <!-- Action Toolbar for Manual Matching -->
            <div class="flex items-center justify-between p-3 rounded-xl bg-secondary /80 text-xs">
                <div class="flex items-center gap-3">
                    <span class="text-muted-foreground">Manual Match:</span>
                    <span class="font-medium {{ $selectedStatementLineId ? 'text-positive font-sans' : 'text-muted-foreground' }}">
                        {{ $selectedStatementLineId ? '1 Statement Line Selected' : 'No statement line selected' }}
                    </span>
                    <span class="text-muted-foreground">•</span>
                    <span class="font-medium {{ $selectedTransactionId ? 'text-positive font-sans' : 'text-muted-foreground' }}">
                        {{ $selectedTransactionId ? '1 Book Transaction Selected' : 'No book transaction selected' }}
                    </span>
                </div>

                <div class="flex items-center gap-2">
                    <button type="button"
                            wire:click="resetMatchSelection"
                            class="px-2.5 py-1 text-muted-foreground hover:text-foreground ">
                        Clear Selection
                    </button>
                    <button type="button"
                            wire:click="manualMatch"
                            @if(! $selectedStatementLineId || ! $selectedTransactionId) disabled @endif
                            class="inline-flex items-center gap-1.5 px-3.5 py-1.5 font-semibold text-primary-foreground bg-primary hover:bg-primary/90 rounded-lg transition-colors disabled:opacity-40 disabled:cursor-not-allowed">
                        <span class="material-symbols-rounded text-sm">link</span>
                        <span>Match Selected</span>
                    </button>
                </div>
            </div>

            <!-- Two-Column Workbench (Statement Lines vs Cash/Bank Transactions) -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <!-- Left: Bank Statement Lines -->
                <div class="card-surface rounded-2xl border border-border overflow-hidden shadow-xs">
                    <div class="p-3 bg-muted/40 border-b border-border flex items-center justify-between">
                        <h4 class="text-xs font-bold text-foreground">
                            Bank Statement Lines (Unreconciled: {{ $unreconciledStatementLines->count() }})
                        </h4>
                    </div>

                    <div class="max-h-96 overflow-y-auto divide-y border-border">
                        @forelse ($unreconciledStatementLines as $line)
                            <label class="p-3 flex items-start gap-3 hover:bg-secondary/60 cursor-pointer transition-colors {{ $selectedStatementLineId === $line->id ? 'bg-secondary' : '' }}">
                                <input type="radio"
                                       name="statement_selection"
                                       value="{{ $line->id }}"
                                       wire:model.live="selectedStatementLineId"
                                       class="mt-0.5 text-foreground focus:ring-ring">
                                <div class="flex-1 min-w-0 text-xs">
                                    @php
                                        $isCredit = bccomp((string) $line->credit_amount, '0.00', 2) > 0;
                                        $lineAmt = $isCredit ? (float) $line->credit_amount : (float) $line->debit_amount;
                                    @endphp
                                    <div class="flex items-center justify-between font-sans">
                                        <span class="text-muted-foreground text-[11px]">{{ $line->value_date ? \Carbon\Carbon::parse($line->value_date)->format('d M Y') : '-' }}</span>
                                        <span class="font-bold {{ $isCredit ? 'text-positive' : 'text-destructive' }}">
                                            Rp {{ number_format($lineAmt, 2) }} ({{ $isCredit ? 'CR' : 'DB' }})
                                        </span>
                                    </div>
                                    <p class="text-foreground font-medium truncate mt-0.5">{{ $line->description ?: '-' }}</p>
                                    @if($line->external_reference)
                                        <span class="text-[10px] text-muted-foreground font-sans">Ref: {{ $line->external_reference }}</span>
                                    @endif
                                </div>
                            </label>
                        @empty
                            <div class="p-8 text-center text-muted-foreground text-xs">
                                No unreconciled bank statement lines found.
                            </div>
                        @endforelse
                    </div>
                </div>

                <!-- Right: Cash/Bank Journal Transactions -->
                <div class="card-surface rounded-2xl border border-border overflow-hidden shadow-xs">
                    <div class="p-3 bg-muted/40 border-b border-border flex items-center justify-between">
                        <h4 class="text-xs font-bold text-foreground">
                            General Ledger Transactions (Unreconciled: {{ $unreconciledTransactions->count() }})
                        </h4>
                    </div>

                    <div class="max-h-96 overflow-y-auto divide-y border-border">
                        @forelse ($unreconciledTransactions as $txn)
                            <label class="p-3 flex items-start gap-3 hover:bg-secondary/60 cursor-pointer transition-colors {{ $selectedTransactionId === $txn->id ? 'bg-secondary' : '' }}">
                                <input type="radio"
                                       name="transaction_selection"
                                       value="{{ $txn->id }}"
                                       wire:model.live="selectedTransactionId"
                                       class="mt-0.5 text-foreground focus:ring-ring">
                                <div class="flex-1 min-w-0 text-xs">
                                    <div class="flex items-center justify-between font-sans">
                                        <span class="text-muted-foreground text-[11px]">{{ \Carbon\Carbon::parse($txn->transaction_date)->format('d M Y') }}</span>
                                        <span class="font-bold {{ in_array($txn->transaction_type->value, ['RECEIPT', 'TRANSFER_IN', 'POS_SHIFT_DEPOSIT', 'PG_SETTLEMENT', 'BANK_INTEREST'], true) ? 'text-positive' : 'text-destructive' }}">
                                            Rp {{ number_format((float) $txn->amount, 2) }} ({{ $txn->transaction_type->value }})
                                        </span>
                                    </div>
                                    <p class="text-foreground font-medium truncate mt-0.5">{{ $txn->description ?: $txn->reference_number }}</p>
                                    <span class="text-[10px] text-muted-foreground font-sans">{{ $txn->transaction_number }}</span>
                                </div>
                            </label>
                        @empty
                            <div class="p-8 text-center text-muted-foreground text-xs">
                                No unreconciled cash/bank transactions found.
                            </div>
                        @endforelse
                    </div>
                </div>
            </div>
        @endif

        <!-- Matched Pairs List -->
        <div class="card-surface rounded-2xl border border-border overflow-hidden shadow-xs">
            <div class="p-4 border-b border-border flex items-center justify-between">
                <h4 class="text-xs font-bold text-foreground">
                    Reconciled Transactions ({{ $matches->count() }} Matched)
                </h4>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead class="bg-secondary /60 text-muted-foreground font-semibold border-b border-border">
                        <tr>
                            <th scope="col" class="px-4 py-2.5">Matched Date</th>
                            <th scope="col" class="px-4 py-2.5">Statement Line</th>
                            <th scope="col" class="px-4 py-2.5">GL Transaction</th>
                            <th scope="col" class="px-4 py-2.5 text-right">Matched Amount</th>
                            <th scope="col" class="px-4 py-2.5 text-center">Match Type</th>
                            <th scope="col" class="px-4 py-2.5 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y border-border">
                        @forelse ($matches as $match)
                            <tr class="hover:bg-secondary/60">
                                <td class="px-4 py-2.5 font-sans text-muted-foreground">{{ $match->created_at->format('d M Y H:i') }}</td>
                                <td class="px-4 py-2.5">
                                    <span class="font-medium text-foreground block">{{ $match->statementLine?->description ?: '-' }}</span>
                                    <span class="font-sans text-[10px] text-muted-foreground">{{ $match->statementLine?->value_date ? \Carbon\Carbon::parse($match->statementLine->value_date)->format('d M Y') : '-' }}</span>
                                </td>
                                <td class="px-4 py-2.5">
                                    <span class="font-medium text-foreground block">{{ $match->transaction?->description ?: $match->transaction?->reference_number }}</span>
                                    <span class="font-sans text-[10px] text-muted-foreground">{{ $match->transaction?->transaction_number }}</span>
                                </td>
                                <td class="tabular-nums px-4 py-2.5 text-right font-sans font-bold text-positive">
                                    Rp {{ number_format((float) $match->matched_amount, 2) }}
                                </td>
                                <td class="px-4 py-2.5">
                                    <span class="text-xs text-muted-foreground font-medium">
                                        {{ $match->match_type }}
                                    </span>
                                </td>
                                <td class="px-4 py-2.5 text-right whitespace-nowrap">
                                    @if ($session->status->value === 'IN_PROGRESS')
                                        <x-ui.table-actions>
                                            <x-ui.table-action-item icon="link_off" variant="destructive" wire:click="unmatch({{ $match->id }})" aria-label="Unmatch Transaction">
                                                Unmatch
                                            </x-ui.table-action-item>
                                        </x-ui.table-actions>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-4 py-8 text-center text-muted-foreground text-xs">
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
            <span class="material-symbols-rounded text-[32px] text-muted-foreground">account_balance</span>
            <h3 class="text-base font-bold text-foreground">No Active Reconciliation Session</h3>
            <p class="text-xs text-muted-foreground max-w-sm mx-auto">Select a cash/bank account and click "Start New Session" to begin matching statement lines.</p>
            <button type="button"
                    wire:click="openStartSessionModal"
                    class="inline-flex items-center gap-1.5 px-4 py-2 text-xs font-semibold text-primary-foreground bg-primary hover:bg-primary/90 rounded-xl transition-colors shadow-xs">
                <span class="material-symbols-rounded text-base">add</span>
                <span>Start Reconciliation Session</span>
            </button>
        </div>
    @endif

    <!-- Start New Session Modal -->
    <x-ui.modal wire:model="showStartSessionModal" title="Start Bank Reconciliation Session" maxWidth="md" onClose="$wire.set('showStartSessionModal', false)">
        <form id="start-session-form" wire:submit="startNewSession" class="space-y-4">
            <div>
                <label class="block text-xs font-semibold text-muted-foreground mb-1">Cash / Bank Account</label>
                <select wire:model="selectedAccountId"
                        class="w-full px-3 py-2 text-xs rounded-xl border border-input bg-background text-foreground">
                    @foreach ($accounts as $acc)
                        <option value="{{ $acc->id }}">[{{ $acc->account_code }}] {{ $acc->account_name }} ({{ $acc->bank_name ?: 'Cash' }})</option>
                    @endforeach
                </select>
                @error('selectedAccountId') <span class="text-[10px] text-destructive mt-1 block">{{ $message }}</span> @enderror
            </div>

            <div>
                <label class="block text-xs font-semibold text-muted-foreground mb-1">Statement Ending Date</label>
                <input type="date"
                       wire:model="statementEndDate"
                       class="w-full px-3 py-2 text-xs rounded-xl border border-input bg-background text-foreground font-sans">
                @error('statementEndDate') <span class="text-[10px] text-destructive mt-1 block">{{ $message }}</span> @enderror
            </div>

            <div>
                <label class="block text-xs font-semibold text-muted-foreground mb-1">Statement Ending Balance</label>
                <input type="number"
                       step="0.01"
                       wire:model="statementEndingBalance"
                       placeholder="e.g. 15000000.00"
                       class="w-full px-3 py-2 text-xs rounded-xl border border-input bg-background text-foreground font-sans font-bold">
                @error('statementEndingBalance') <span class="text-[10px] text-destructive mt-1 block">{{ $message }}</span> @enderror
            </div>

            <div>
                <label class="block text-xs font-semibold text-muted-foreground mb-1">Session Notes (Optional)</label>
                <input type="text"
                       wire:model="sessionNotes"
                       placeholder="e.g. Monthly reconciliation for operational account..."
                       class="w-full px-3 py-2 text-xs rounded-xl border border-input bg-background text-foreground">
            </div>
        </form>

        <x-slot:footer>
            <div class="flex items-center justify-end gap-2 w-full">
                <button type="button"
                        wire:click="$set('showStartSessionModal', false)"
                        class="px-4 py-2 text-xs font-semibold text-muted-foreground hover:text-foreground rounded-xl transition-colors">
                    Cancel
                </button>
                <button type="submit" form="start-session-form" wire:loading.attr="disabled"
                        class="px-4 py-2 text-xs font-semibold text-primary-foreground bg-primary hover:bg-primary/90 rounded-xl transition-colors shadow-xs disabled:opacity-50">
                    Start Session
                </button>
            </div>
        </x-slot:footer>
    </x-ui.modal>
</div>
