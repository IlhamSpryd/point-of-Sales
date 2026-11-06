@props([
    'totalDebit' => 0,
    'totalCredit' => 0,
])

@php
    $debit = (float) $totalDebit;
    $credit = (float) $totalCredit;
    $diff = abs($debit - $credit);
    $isBalanced = $diff < 0.01 && ($debit > 0 || $credit > 0);
    $isEmpty = $debit == 0 && $credit == 0;
@endphp

<div {{ $attributes->twMerge('rounded-xl border p-4 shadow-xs transition-all duration-200 select-none ' . (
    $isEmpty
        ? 'border-border bg-card'
        : ($isBalanced
            ? 'border-success-border bg-success-muted'
            : 'border-destructive-border bg-destructive-muted')
)) }}>
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
        <div class="flex items-center gap-3">
            <div class="size-10 rounded-xl flex items-center justify-center shrink-0 border {{
                $isEmpty
                    ? 'bg-muted text-muted-foreground border-border'
                    : ($isBalanced
                        ? 'bg-success-muted text-success border-success-border'
                        : 'bg-destructive-muted text-destructive border-destructive-border')
            }}">
                <span class="material-symbols-rounded text-2xl">
                    {{ $isEmpty ? 'account_balance_wallet' : ($isBalanced ? 'check_circle' : 'error') }}
                </span>
            </div>

            <div>
                <h4 class="text-sm font-bold text-foreground tracking-tight">
                    @if ($isEmpty)
                        Journal Balance Status
                    @elseif ($isBalanced)
                        Journal Balanced
                    @else
                        Journal Unbalanced
                    @endif
                </h4>
                <p class="text-xs text-muted-foreground mt-0.5">
                    @if ($isEmpty)
                        Add debit and credit account lines to validate journal balance.
                    @elseif ($isBalanced)
                        Total debits and credits are equal. Journal satisfies double-entry bookkeeping rules.
                    @else
                        Difference detected between debits and credits. Posting blocked until balanced.
                    @endif
                </p>
            </div>
        </div>

        {{-- Totals Cluster --}}
        <div class="flex items-center gap-3 self-end sm:self-center">
            <div class="text-right">
                <span class="text-[10px] font-bold uppercase tracking-wider text-muted-foreground block">
                    Total Debit
                </span>
                <span class="text-sm font-extrabold text-foreground numeric tabular-nums">
                    Rp {{ number_format($debit, 0, ',', '.') }}
                </span>
            </div>

            <span class="text-muted-foreground text-sm font-bold">&bull;</span>

            <div class="text-right">
                <span class="text-[10px] font-bold uppercase tracking-wider text-muted-foreground block">
                    Total Credit
                </span>
                <span class="text-sm font-extrabold text-foreground numeric tabular-nums">
                    Rp {{ number_format($credit, 0, ',', '.') }}
                </span>
            </div>

            @if (!$isBalanced && !$isEmpty)
                <div class="ml-2 pl-3 border-l border-border text-right">
                    <span class="text-[10px] font-bold uppercase tracking-wider text-destructive block">
                        Difference
                    </span>
                    <span class="text-sm font-extrabold text-destructive numeric tabular-nums">
                        Rp {{ number_format($diff, 0, ',', '.') }}
                    </span>
                </div>
            @endif
        </div>
    </div>
</div>
