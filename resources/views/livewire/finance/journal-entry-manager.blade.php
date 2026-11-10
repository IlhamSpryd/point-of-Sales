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

    <!-- Header Action Bar -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-xl font-bold text-zinc-950 dark:text-white tracking-tight">General Journal (General Ledger)</h1>
            <p class="text-xs text-zinc-500">Dual-entry general ledger, automated transaction posting, and manual adjustment workbench.</p>
        </div>
        <button type="button"
                wire:click="openWorkbenchModal"
                class="inline-flex items-center justify-center gap-2 px-4 py-2 text-xs font-semibold text-white bg-zinc-950 hover:bg-zinc-800 dark:bg-white dark:text-zinc-950 dark:hover:bg-zinc-200 rounded-xl transition-colors shadow-xs">
            <span class="material-symbols-rounded text-base">post_add</span>
            <span>Create Manual Journal</span>
        </button>
    </div>

    <!-- Filter & Search Toolbar -->
    <x-erp.filter-bar search-placeholder="Search journal number, reference...">
        <x-slot:filters>
            <select wire:model.live="statusFilter" class="h-9 text-xs rounded-xl border border-input bg-background text-foreground px-3 outline-none">
                <option value="">All Statuses</option>
                @foreach ($statuses as $status)
                    <option value="{{ $status->value }}">{{ $status->value }}</option>
                @endforeach
            </select>

            <div class="flex items-center gap-1.5 text-xs text-muted-foreground">
                <span>From:</span>
                <input type="date"
                       wire:model.live="dateFrom"
                       class="h-9 px-2.5 text-xs rounded-xl border border-input bg-background text-foreground focus:outline-none font-sans">
                <span>To:</span>
                <input type="date"
                       wire:model.live="dateTo"
                       class="h-9 px-2.5 text-xs rounded-xl border border-input bg-background text-foreground focus:outline-none font-sans">
            </div>
        </x-slot:filters>
    </x-erp.filter-bar>

    @php
        $journalColumns = [
            ['key' => 'journal_number', 'label' => 'Journal Number', 'sortable' => true],
            ['key' => 'posting_date', 'label' => 'Posting Date', 'sortable' => true],
            ['key' => 'source_module', 'label' => 'Source & Type'],
            ['key' => 'notes', 'label' => 'Notes'],
            ['key' => 'total_debit', 'label' => 'Total Debit', 'numeric' => true, 'sortable' => true],
            ['key' => 'total_credit', 'label' => 'Total Credit', 'numeric' => true, 'sortable' => true],
            ['key' => 'status', 'label' => 'Status', 'align' => 'center'],
            ['key' => 'actions', 'label' => 'Actions', 'align' => 'right'],
        ];
    @endphp

    <!-- Table Container -->
    <x-erp.data-table
        :columns="$journalColumns"
        :rows="$journals"
        loading-target="search, filterModule, filterStatus"
        :is-empty="$journals->isEmpty()"
        empty-title="No general journal entries found."
        empty-description="Create a new manual journal or check active filters."
        empty-icon="receipt_long"
        :colspan="8"
    >
        @foreach ($journals as $journal)
                        <tr class="hover:bg-muted/40 transition-colors">
                            <td class="px-4 py-3 font-sans font-medium text-foreground">
                                {{ $journal->journal_number }}
                            </td>
                            <td class="px-4 py-3 font-sans text-muted-foreground text-xs">
                                {{ $journal->posting_date->format('d M Y') }}
                            </td>
                            <td class="px-4 py-3">
                                <span class="font-semibold text-foreground text-xs">{{ $journal->source_module }}</span>
                                <span class="text-[10px] text-muted-foreground block">{{ $journal->journal_type->value }}</span>
                            </td>
                            <td class="px-4 py-3 text-muted-foreground max-w-xs truncate text-xs" title="{{ $journal->notes }}">
                                {{ $journal->notes ?: '-' }}
                            </td>
                            <td class="px-4 py-3 text-right font-sans font-semibold text-foreground tabular-nums">
                                Rp {{ number_format((float) $journal->total_debit, 2) }}
                            </td>
                            <td class="px-4 py-3 text-right font-sans font-semibold text-foreground tabular-nums">
                                Rp {{ number_format((float) $journal->total_credit, 2) }}
                            </td>
                            <td class="px-4 py-3">
                                @php
                                    $journalDot = match(strtoupper($journal->status->value)) {
                                        'POSTED' => 'bg-success',
                                        'REVERSED' => 'bg-destructive',
                                        'DRAFT' => 'bg-neutral',
                                        default => 'bg-neutral',
                                    };
                                @endphp
                                <span class="inline-flex items-center gap-1.5 text-xs text-foreground font-medium">
                                    <span class="w-1.5 h-1.5 rounded-full {{ $journalDot }}"></span>
                                    {{ $journal->status->value }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-right whitespace-nowrap">
                                <x-ui.table-actions>
                                    <x-ui.table-action-item icon="visibility" wire:click="viewJournal({{ $journal->id }})">
                                        View Lines
                                    </x-ui.table-action-item>
                                    @if ($journal->status->value === 'POSTED' && ! $journal->reversed_by_journal_id)
                                        <x-ui.table-action-item icon="undo" variant="destructive" wire:click="openReversalModal({{ $journal->id }})">
                                            Reverse Journal
                                        </x-ui.table-action-item>
                                    @endif
                                </x-ui.table-actions>
                            </td>
                        </tr>
        @endforeach
    </x-erp.data-table>

    <!-- Workbench Modal: Manual Journal Creation -->
    <x-ui.modal wire:model="showWorkbenchModal" maxWidth="4xl" onClose="$wire.set('showWorkbenchModal', false)">
        <x-slot:header>
            <div>
                <h3 class="text-base font-bold text-foreground">Manual Journal Workbench (Dual-Entry)</h3>
                <p class="text-xs text-muted-foreground">Real-time debit and credit balance validation before posting to General Ledger.</p>
            </div>
        </x-slot:header>

        <form id="workbench-form" wire:submit="postManualJournal" class="space-y-4">
                    <x-erp.dirty-guard watch="lines" />
                    <!-- General Header Information -->
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-xs font-semibold text-zinc-700 dark:text-zinc-300 mb-1">Posting Date</label>
                            <input type="date"
                                   wire:model="postingDate"
                                   class="w-full px-3 py-2 text-xs rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 text-zinc-900 dark:text-zinc-100 font-sans">
                            @error('postingDate') <span class="text-[10px] text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-zinc-700 dark:text-zinc-300 mb-1">Journal Type</label>
                            <select wire:model="journalType"
                                    class="w-full px-3 py-2 text-xs rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 text-zinc-900 dark:text-zinc-100">
                                <option value="MANUAL_ADJUSTMENT">MANUAL_ADJUSTMENT (Adjusting Journal)</option>
                                <option value="OPENING_BALANCE">OPENING_BALANCE (Opening Balance)</option>
                            </select>
                            @error('journalType') <span class="text-[10px] text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-zinc-700 dark:text-zinc-300 mb-1">Notes / Description</label>
                            <input type="text"
                                   wire:model="notes"
                                   placeholder="e.g. Month-end operational expense adjustment..."
                                   class="w-full px-3 py-2 text-xs rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 text-zinc-900 dark:text-zinc-100">
                            @error('notes') <span class="text-[10px] text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                        </div>
                    </div>

                    <!-- Journal Lines Editor Table -->
                    <div class="space-y-2 pt-2">
                        <div class="flex items-center justify-between">
                            <span class="text-xs font-bold text-zinc-900 dark:text-zinc-100">Journal Entry Lines</span>
                            <button type="button"
                                    wire:click="addLine"
                                    class="inline-flex items-center gap-1 px-3 py-1 text-xs font-semibold text-zinc-800 dark:text-zinc-200 bg-zinc-100 dark:bg-zinc-800 hover:bg-zinc-200 dark:hover:bg-zinc-700 rounded-lg transition-colors">
                                <span class="material-symbols-rounded text-sm">add</span>
                                <span>Add Line</span>
                            </button>
                        </div>

                        @error('lines')
                            <div class="p-3 rounded-xl bg-rose-500/10 border border-rose-500/20 text-rose-600 dark:text-rose-400 text-xs">
                                {{ $message }}
                            </div>
                        @enderror

                        <div class="border border-zinc-200 dark:border-zinc-800 rounded-xl overflow-hidden">
                            <table class="w-full text-left text-xs">
                                <thead class="bg-zinc-50 dark:bg-zinc-900/60 text-zinc-500 font-semibold border-b border-zinc-200 dark:border-zinc-800">
                                    <tr>
                                        <th class="px-3 py-2.5 w-1/3">COA Account</th>
                                        <th class="px-3 py-2.5 w-1/4">Line Description</th>
                                        <th class="px-3 py-2.5 text-right w-1/6">Debit (Rp)</th>
                                        <th class="px-3 py-2.5 text-right w-1/6">Credit (Rp)</th>
                                        <th class="px-2 py-2.5 text-center w-10"></th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800/60">
                                    @foreach ($lines as $index => $line)
                                        <tr>
                                            <td class="p-2">
                                                @php
                                                    $accOptions = collect($accounts)->map(fn($acc) => [
                                                        'value' => (string) $acc->id,
                                                        'label' => "[{$acc->account_code}] {$acc->account_name} ({$acc->normal_balance->value})"
                                                    ])->all();
                                                @endphp
                                                <x-erp.searchable-select
                                                    wire:model.live="lines.{{ $index }}.account_id"
                                                    :options="$accOptions"
                                                    placeholder="-- Select Account --"
                                                />
                                                @error("lines.{$index}.account_id") <span class="text-[10px] text-destructive block mt-1">{{ $message }}</span> @enderror
                                            </td>
                                            <td class="p-2">
                                                <input type="text"
                                                       wire:model="lines.{{ $index }}.description"
                                                       placeholder="Line description (optional)"
                                                       class="w-full px-2.5 py-1.5 text-xs rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 text-zinc-900 dark:text-zinc-100">
                                            </td>
                                            <td class="p-2">
                                                <input type="number"
                                                       step="0.01"
                                                       min="0"
                                                       wire:model.live.debounce.300ms="lines.{{ $index }}.debit_amount"
                                                       class="w-full px-2.5 py-1.5 text-xs text-right font-sans rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 text-zinc-900 dark:text-zinc-100 font-semibold">
                                                @error("lines.{$index}.debit_amount") <span class="text-[10px] text-rose-500 block text-right">{{ $message }}</span> @enderror
                                            </td>
                                            <td class="p-2">
                                                <input type="number"
                                                       step="0.01"
                                                       min="0"
                                                       wire:model.live.debounce.300ms="lines.{{ $index }}.credit_amount"
                                                       class="w-full px-2.5 py-1.5 text-xs text-right font-sans rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 text-zinc-900 dark:text-zinc-100 font-semibold">
                                                @error("lines.{$index}.credit_amount") <span class="text-[10px] text-rose-500 block text-right">{{ $message }}</span> @enderror
                                            </td>
                                            <td class="p-2 text-center">
                                                @if (count($lines) > 2)
                                                    <button type="button"
                                                            wire:click="removeLine({{ $index }})"
                                                            class="p-1 rounded text-zinc-400 hover:text-rose-500 transition-colors">
                                                        <span class="material-symbols-rounded text-sm">delete</span>
                                                    </button>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Real-Time Balancing Summary Banner -->
                    @php $balance = $this->balanceSummary; @endphp
                    <div class="p-4 rounded-xl border {{ $balance['isBalanced'] ? 'bg-emerald-500/5 border-emerald-500/20' : 'bg-rose-500/5 border-rose-500/20' }} flex flex-col sm:flex-row items-center justify-between gap-4">
                        <div class="flex items-center gap-3">
                            <div class="w-8 h-8 rounded-lg {{ $balance['isBalanced'] ? 'bg-emerald-500/20 text-emerald-600 dark:text-emerald-400' : 'bg-rose-500/20 text-rose-600 dark:text-rose-400' }} flex items-center justify-center">
                                <span class="material-symbols-rounded text-lg">{{ $balance['isBalanced'] ? 'check_circle' : 'warning' }}</span>
                            </div>
                            <div>
                                <span class="text-xs font-bold {{ $balance['isBalanced'] ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400' }}">
                                    {{ $balance['isBalanced'] ? 'Journal Balanced (Ready to Post)' : 'Journal Unbalanced' }}
                                </span>
                                <span class="text-[11px] text-zinc-500 block font-sans">
                                    Difference: Rp {{ number_format((float) abs((float) $balance['difference']), 2) }}
                                </span>
                            </div>
                        </div>

                        <div class="flex items-center gap-6 font-sans text-xs">
                            <div class="text-right">
                                <span class="text-[10px] text-zinc-400 block uppercase">Total Debit</span>
                                <span class="font-bold text-zinc-950 dark:text-white">Rp {{ number_format((float) $balance['totalDebit'], 2) }}</span>
                            </div>
                            <div class="text-right">
                                <span class="text-[10px] text-zinc-400 block uppercase">Total Credit</span>
                                <span class="font-bold text-zinc-950 dark:text-white">Rp {{ number_format((float) $balance['totalCredit'], 2) }}</span>
                            </div>
                        </div>
                    </div>

        </form>

        <x-slot:footer>
            <div class="flex items-center justify-end gap-2 w-full">
                <button type="button"
                        wire:click="$set('showWorkbenchModal', false)"
                        class="px-4 py-2 text-xs font-semibold text-muted-foreground hover:text-foreground rounded-xl transition-colors">
                    Cancel
                </button>
                <button type="submit"
                        form="workbench-form"
                        wire:loading.attr="disabled"
                        wire:target="postManualJournal"
                        @if(! $balance['isBalanced']) disabled @endif
                        class="inline-flex items-center gap-2 px-5 py-2 text-xs font-semibold text-primary-foreground bg-primary hover:bg-primary/90 rounded-xl transition-colors shadow-xs disabled:opacity-40 disabled:cursor-not-allowed">
                    <span class="material-symbols-rounded text-sm">send</span>
                    <span>Post to General Ledger</span>
                </button>
            </div>
        </x-slot:footer>
    </x-ui.modal>

    <!-- Journal Detail Modal -->
    @if ($showDetailModal && $selectedJournal)
        <x-ui.modal :open="true" maxWidth="3xl" onClose="$wire.set('showDetailModal', false)">
            <x-slot:title>
                <div>
                    <h3 class="text-base font-bold text-foreground font-sans">
                        {{ $selectedJournal->journal_number }}
                    </h3>
                    <p class="text-xs text-muted-foreground">Posting Date: {{ $selectedJournal->posting_date->format('d M Y') }} • Type: {{ $selectedJournal->journal_type->value }}</p>
                </div>
            </x-slot:title>

            <div class="space-y-3">
                <div class="p-3 bg-muted/50 rounded-xl text-xs space-y-1 border border-border">
                    <div class="flex justify-between">
                        <span class="text-muted-foreground">Notes:</span>
                        <span class="text-foreground font-medium">{{ $selectedJournal->notes ?: '-' }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-muted-foreground">Posted By:</span>
                        <span class="text-foreground font-medium">{{ $selectedJournal->createdByUser?->name ?? 'System Auto' }}</span>
                    </div>
                    @if($selectedJournal->reversed_by_journal_id)
                        <div class="flex justify-between text-destructive">
                            <span>Reversal Status:</span>
                            <span>Reversed by #{{ $selectedJournal->reversed_by_journal_id }} ({{ $selectedJournal->reversal_reason }})</span>
                        </div>
                    @endif
                </div>

                <div class="border border-border rounded-xl overflow-hidden">
                    <table class="w-full text-left text-xs">
                        <thead class="bg-muted/50 text-muted-foreground font-semibold border-b border-border">
                            <tr>
                                <th class="px-3 py-2">Account Code</th>
                                <th class="px-3 py-2">Account Name</th>
                                <th class="px-3 py-2">Line Description</th>
                                <th class="px-3 py-2 text-right">Debit</th>
                                <th class="px-3 py-2 text-right">Credit</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            @foreach ($selectedJournal->lines as $line)
                                <tr>
                                    <td class="px-3 py-2 font-sans text-foreground font-medium">{{ $line->account?->account_code ?? $line->account_id }}</td>
                                    <td class="px-3 py-2 text-foreground">{{ $line->account?->account_name ?? '-' }}</td>
                                    <td class="px-3 py-2 text-muted-foreground">{{ $line->description ?: '-' }}</td>
                                    <td class="px-3 py-2 text-right font-sans font-medium text-foreground">
                                        {{ (float) $line->debit_amount > 0 ? 'Rp ' . number_format((float) $line->debit_amount, 2) : '-' }}
                                    </td>
                                    <td class="px-3 py-2 text-right font-sans font-medium text-foreground">
                                        {{ (float) $line->credit_amount > 0 ? 'Rp ' . number_format((float) $line->credit_amount, 2) : '-' }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot class="bg-muted/50 font-sans font-bold text-xs border-t border-border">
                            <tr>
                                <td colspan="3" class="px-3 py-2 text-right">Total</td>
                                <td class="px-3 py-2 text-right">Rp {{ number_format((float) $selectedJournal->total_debit, 2) }}</td>
                                <td class="px-3 py-2 text-right">Rp {{ number_format((float) $selectedJournal->total_credit, 2) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>

            <x-slot:footer>
                <div class="flex justify-end w-full">
                    <button type="button"
                            wire:click="$set('showDetailModal', false)"
                            class="px-4 py-2 text-xs font-semibold text-muted-foreground hover:text-foreground rounded-xl transition-colors">
                        Close
                    </button>
                </div>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    <!-- Reversal Confirmation Modal -->
    @if ($showReversalModal)
        <x-ui.modal wire:model="showReversalModal" title="Confirm Journal Reversal" maxWidth="md" onClose="$wire.set('showReversalModal', false)">
            <div class="space-y-4">
                <p class="text-xs text-muted-foreground">
                    This action will automatically generate a symmetric reversal journal (swapping debits and credits) in the General Ledger and mark this journal as REVERSED.
                </p>

                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">Reversal Reason (Required)</label>
                    <textarea wire:model="reversalReason"
                              rows="3"
                              placeholder="Enter reason for journal cancellation or adjustment..."
                              class="w-full px-3 py-2 text-xs rounded-xl border border-border bg-background text-foreground focus:outline-none focus:ring-1 focus:ring-ring"></textarea>
                    @error('reversalReason') <span class="text-2xs text-destructive mt-1 block">{{ $message }}</span> @enderror
                </div>
            </div>

            <x-slot:footer>
                <div class="flex items-center justify-end gap-2 w-full">
                    <button type="button"
                            wire:click="$set('showReversalModal', false)"
                            class="px-4 py-2 text-xs font-semibold text-muted-foreground hover:text-foreground rounded-xl transition-colors">
                        Cancel
                    </button>
                    <button type="button"
                            wire:click="confirmReversal"
                            class="px-4 py-2 text-xs font-semibold text-destructive-foreground bg-destructive hover:bg-destructive/90 rounded-xl transition-colors shadow-xs">
                        Confirm Reversal
                    </button>
                </div>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
