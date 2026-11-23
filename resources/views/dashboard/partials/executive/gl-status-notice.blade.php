{{-- ========================================================================= --}}
{{-- 3-STATE GL RECONCILIATION NOTICE — SLIM HAIRLINE BAR & ZERO PILLS         --}}
{{-- ========================================================================= --}}
@if(isset($executiveData['glTrustIndicator']))
    @php
        $glTrust = $executiveData['glTrustIndicator'];
        $glVariance = (float) ($glTrust['reconciliation_variance'] ?? 0);
        $isReconciled = !empty($glTrust['is_reconciled']);
        $isAuditPending = ($glTrust['status_state'] ?? '') === 'audit_pending' || (!$isReconciled && abs($glVariance) < 0.01);
    @endphp
    @if($isReconciled)
        <div class="rounded-[10px] border border-border bg-card p-3 sm:py-2.5 sm:px-3.5 flex flex-col sm:flex-row sm:items-center justify-between gap-2 border-l-2 border-l-positive">
            <div class="flex items-center gap-2.5">
                <span class="w-2 h-2 rounded-full bg-positive shrink-0"></span>
                <div>
                    <span class="text-xs font-semibold text-foreground">General Ledger in Sync: POS Subledger &amp; GL Match 100%</span>
                    <span class="text-[11px] text-muted-foreground ml-1.5 hidden sm:inline">
                        · Audit Rekonsiliasi Kasir &amp; GL Cocok (Variance: Rp 0) · Standar PSAK / S/4HANA Accrual
                    </span>
                </div>
            </div>
            <div class="flex items-center gap-1.5 text-[11px] font-medium text-positive shrink-0 self-start sm:self-auto">
                <span class="material-symbols-rounded text-sm">verified</span>
                <span>Audited &amp; Reconciled</span>
            </div>
        </div>
    @elseif($isAuditPending)
        <div class="rounded-[10px] border border-border bg-card p-3 sm:py-2.5 sm:px-3.5 flex flex-col sm:flex-row sm:items-center justify-between gap-2 border-l-2 border-l-warning">
            <div class="flex items-center gap-2.5">
                <span class="w-2 h-2 rounded-full bg-warning shrink-0"></span>
                <div>
                    <span class="text-xs font-semibold text-foreground">Audit Shift Berjalan: Buku Besar Sinkron (Variance: Rp 0)</span>
                    <span class="text-[11px] text-muted-foreground ml-1.5 hidden sm:inline">
                        · {{ $glTrust['status_text'] ?? 'Shift kasir aktif belum ditutup' }} · Subledger POS dan Jurnal GL seimbang tanpa selisih transaksi.
                    </span>
                </div>
            </div>
            <div class="flex items-center gap-1.5 text-[11px] font-medium text-warning shrink-0 self-start sm:self-auto">
                <span class="material-symbols-rounded text-sm">schedule</span>
                <span>{{ $glTrust['status_badge'] ?? 'Audit Pending' }}</span>
            </div>
        </div>
    @else
        <div class="rounded-[10px] border border-border bg-card p-3 sm:py-2.5 sm:px-3.5 flex flex-col sm:flex-row sm:items-center justify-between gap-2 border-l-2 border-l-destructive">
            <div class="flex items-center gap-2.5">
                <span class="w-2 h-2 rounded-full bg-destructive shrink-0"></span>
                <div>
                    <span class="text-xs font-semibold text-destructive">GL Reconciliation Variance Detected</span>
                    <span class="text-[11px] text-muted-foreground ml-1.5 hidden sm:inline">
                        · Selisih Rp {{ number_format($glVariance, 0, ',', '.') }} antara POS Subledger (Rp {{ number_format($glTrust['net_pos_sales'] ?? 0, 0, ',', '.') }}) dan Jurnal GL (Rp {{ number_format($glTrust['sales_journal_balance'] ?? 0, 0, ',', '.') }}).
                    </span>
                </div>
            </div>
            <div class="flex items-center gap-1.5 text-[11px] font-medium text-destructive shrink-0 self-start sm:self-auto">
                <span class="material-symbols-rounded text-sm">warning</span>
                <span>Attention Required</span>
            </div>
        </div>
    @endif
@endif
