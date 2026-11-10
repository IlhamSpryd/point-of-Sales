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
            <h1 class="text-xl font-bold text-foreground tracking-tight">Accounting Periods & Book Closing</h1>
            <p class="text-xs text-muted-foreground">Manage monthly book closing cycles (soft-close / hard-close) and permanent fiscal year locking.</p>
        </div>

        <div class="flex items-center gap-3">
            <div class="flex items-center gap-2 text-xs">
                <span class="text-muted-foreground font-medium">Fiscal Year:</span>
                <select wire:model.live="selectedFiscalYear"
                        class="px-3 py-1.5 rounded-xl border border-border bg-card text-foreground font-bold focus:outline-none focus:ring-1 focus:ring-ring">
                    @foreach ($availableYears as $year)
                        <option value="{{ $year }}">{{ $year }}</option>
                    @endforeach
                </select>
            </div>

            @if ($periods->isEmpty())
                <button type="button"
                        wire:click="generateFiscalYearPeriods"
                        class="inline-flex items-center gap-1.5 px-4 py-2 text-xs font-semibold text-primary-foreground bg-primary hover:bg-primary/90 rounded-xl transition-colors shadow-xs">
                    <span class="material-symbols-rounded text-base">calendar_month</span>
                    <span>Generate 12 Periods ({{ $selectedFiscalYear }})</span>
                </button>
            @endif
        </div>
    </div>

    <!-- Periods Table -->
    <div class="card-surface rounded-2xl border border-border overflow-hidden shadow-xs">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs">
                <thead class="bg-muted/50 text-muted-foreground font-semibold border-b border-border">
                    <tr>
                        <th class="px-4 py-3">Period Code</th>
                        <th class="px-4 py-3">Period / Month</th>
                        <th class="px-4 py-3">Date Range</th>
                        <th class="px-4 py-3 text-center">Period Status</th>
                        <th class="px-4 py-3">Posting Permission</th>
                        <th class="px-4 py-3 text-right">Cycle Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($periods as $period)
                        <tr class="hover:bg-muted/30 transition-colors">
                            <td class="px-4 py-3 font-sans font-bold text-foreground">
                                {{ $period->period_code }}
                            </td>
                            <td class="px-4 py-3 font-medium text-foreground">
                                Period #{{ $period->period_number }} ({{ \Carbon\Carbon::parse($period->start_date)->format('F') }})
                            </td>
                            <td class="px-4 py-3 font-sans text-muted-foreground">
                                {{ \Carbon\Carbon::parse($period->start_date)->format('d M Y') }} — {{ \Carbon\Carbon::parse($period->end_date)->format('d M Y') }}
                            </td>
                            <td class="px-4 py-3">
                                @php
                                    $periodDot = match($period->status->value) {
                                        'OPEN' => 'bg-success',
                                        'SOFT_CLOSED' => 'bg-warning',
                                        'CLOSED' => 'bg-info',
                                        default => 'bg-neutral',
                                    };
                                @endphp
                                <span class="inline-flex items-center gap-1.5 text-xs text-foreground font-medium">
                                    <span class="w-1.5 h-1.5 rounded-full {{ $periodDot }}"></span>
                                    {{ $period->status->value }}
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                @if ($period->status->isPostingAllowed())
                                    <span class="inline-flex items-center gap-1 text-[11px] text-emerald-600 dark:text-emerald-400 font-medium">
                                        <span class="material-symbols-rounded text-sm">lock_open</span> Allowed
                                    </span>
                                @else
                                    <span class="inline-flex items-center gap-1 text-[11px] text-destructive font-medium">
                                        <span class="material-symbols-rounded text-sm">lock</span> Locked (Posting Rejected)
                                    </span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right whitespace-nowrap">
                                <x-ui.table-actions>
                                    <x-ui.table-action-item icon="fact_check" wire:click="evaluateGates({{ $period->id }})" aria-label="Evaluate Closing Gates">
                                        Closing Gates
                                    </x-ui.table-action-item>
                                    @if ($period->status->value === 'OPEN')
                                        <x-ui.table-action-item icon="lock_clock" wire:click="softClose({{ $period->id }})" aria-label="Soft Close Period">
                                            Soft Close
                                        </x-ui.table-action-item>
                                        <x-ui.table-action-item icon="lock" wire:click="hardClose({{ $period->id }})" aria-label="Hard Close Period">
                                            Close Period
                                        </x-ui.table-action-item>
                                    @elseif ($period->status->value === 'SOFT_CLOSED')
                                        <x-ui.table-action-item icon="lock" wire:click="hardClose({{ $period->id }})" aria-label="Hard Close Period">
                                            Close Period
                                        </x-ui.table-action-item>
                                        <x-ui.table-action-item icon="lock_open" wire:click="openReopenModal({{ $period->id }})" aria-label="Reopen Period">
                                            Reopen Period
                                        </x-ui.table-action-item>
                                    @elseif ($period->status->value === 'CLOSED')
                                        <x-ui.table-action-item icon="lock_person" variant="destructive" wire:click="lock({{ $period->id }})" aria-label="Permanent Lock Period">
                                            Permanent Lock
                                        </x-ui.table-action-item>
                                        <x-ui.table-action-item icon="lock_open" wire:click="openReopenModal({{ $period->id }})" aria-label="Reopen Period">
                                            Reopen Period
                                        </x-ui.table-action-item>
                                    @endif
                                </x-ui.table-actions>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-4 py-12 text-center text-muted-foreground">
                                <span class="material-symbols-rounded text-3xl mb-1 text-muted-foreground/60">event_busy</span>
                                <p class="text-xs">No accounting periods found for fiscal year {{ $selectedFiscalYear }}.</p>
                                <button type="button"
                                        wire:click="generateFiscalYearPeriods"
                                        class="mt-3 inline-flex items-center gap-1.5 px-4 py-2 text-xs font-semibold text-primary-foreground bg-primary hover:bg-primary/90 rounded-xl transition-colors shadow-xs">
                                    <span class="material-symbols-rounded text-base">add</span>
                                    <span>Generate 12 Periods Now</span>
                                </button>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <!-- Reopen Modal -->
    @if ($showReopenModal)
        <x-ui.modal wire:model="showReopenModal" title="Reopen Accounting Period" maxWidth="md" onClose="$wire.set('showReopenModal', false)">
            <div class="space-y-4">
                <p class="text-xs text-muted-foreground">
                    Reopening a closed accounting period requires authorization and official audit log recording. Enter reopening justification:
                </p>

                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">Reopen Reason (Required)</label>
                    <textarea wire:model="reopenReason"
                              rows="3"
                              placeholder="e.g. External audit adjustment correction..."
                              class="w-full px-3 py-2 text-xs rounded-xl border border-border bg-background text-foreground focus:outline-none focus:ring-1 focus:ring-ring"></textarea>
                    @error('reopenReason') <span class="text-2xs text-destructive mt-1 block">{{ $message }}</span> @enderror
                </div>
            </div>

            <x-slot:footer>
                <div class="flex items-center justify-end gap-2 w-full">
                    <button type="button"
                            wire:click="$set('showReopenModal', false)"
                            class="px-4 py-2 text-xs font-semibold text-muted-foreground hover:text-foreground rounded-xl transition-colors">
                        Cancel
                    </button>
                    <button type="button"
                            wire:click="confirmReopen"
                            class="px-4 py-2 text-xs font-semibold text-primary-foreground bg-primary hover:bg-primary/90 rounded-xl transition-colors shadow-xs">
                        Confirm Reopen Period
                    </button>
                </div>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    <!-- Gates Evaluation Modal -->
    @if ($showGatesModal && $gatesPeriod)
        <x-ui.modal :open="true" maxWidth="lg" onClose="$wire.set('showGatesModal', false)">
            <x-slot:title>
                <div>
                    <h3 class="text-base font-bold text-foreground">
                        Closing Gates Checklist — Period {{ $gatesPeriod->period_code }}
                    </h3>
                    <p class="text-xs text-muted-foreground">Pre-requisite evaluations for General Ledger book closing.</p>
                </div>
            </x-slot:title>

            <div class="space-y-2">
                @forelse ($gatesResults as $gate => $result)
                    <div class="p-3 rounded-xl border border-border flex items-center justify-between text-xs bg-muted/30">
                        <span class="font-medium text-foreground">{{ ucwords(str_replace('_', ' ', (string) $gate)) }}</span>
                        @if (is_array($result) ? ($result['passed'] ?? false) : (bool) $result)
                            <span class="px-2 py-0.5 rounded-full text-2xs font-bold bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 inline-flex items-center gap-1">
                                <span class="material-symbols-rounded text-xs">check</span> PASSED
                            </span>
                        @else
                            <span class="px-2 py-0.5 rounded-full text-2xs font-bold bg-amber-500/10 text-amber-600 dark:text-amber-400 inline-flex items-center gap-1">
                                <span class="material-symbols-rounded text-xs">warning</span> PENDING
                            </span>
                        @endif
                    </div>
                @empty
                    <div class="p-4 text-center text-muted-foreground text-xs">
                        All closing gates satisfied.
                    </div>
                @endforelse
            </div>

            <x-slot:footer>
                <div class="flex justify-end w-full">
                    <button type="button"
                            wire:click="$set('showGatesModal', false)"
                            class="px-4 py-2 text-xs font-semibold text-muted-foreground hover:text-foreground rounded-xl transition-colors">
                        Close
                    </button>
                </div>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
