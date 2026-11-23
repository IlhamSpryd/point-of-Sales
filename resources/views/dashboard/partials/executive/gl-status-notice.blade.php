{{-- ========================================================================= --}}
{{-- 3-STATE GL RECONCILIATION NOTICE & PLAN VS ACTUAL BANNER (KPI-PLN-01)    --}}
{{-- ========================================================================= --}}
@if(isset($executiveData['glTrustIndicator']))
    @php
        $glTrust = $executiveData['glTrustIndicator'];
        $glVariance = (float) ($glTrust['reconciliation_variance'] ?? 0);
        $isReconciled = !empty($glTrust['is_reconciled']);
        $isAuditPending = ($glTrust['status_state'] ?? '') === 'audit_pending' || (!$isReconciled && abs($glVariance) < 0.01);
    @endphp
    @if($isReconciled)
        <div class="rounded-xl border border-success-border bg-success-muted p-4 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
            <div class="flex items-center gap-3">
                <span class="material-symbols-rounded text-success text-2xl">verified_user</span>
                <div>
                    <div class="text-xs sm:text-sm font-bold text-foreground">General Ledger in Sync: POS Subledger &amp; GL Match 100%</div>
                    <div class="text-xs text-muted-foreground mt-0.5">
                        Audit Rekonsiliasi Kasir &amp; GL Cocok (Variance: Rp 0) · Standar PSAK / S/4HANA Accrual
                    </div>
                </div>
            </div>
            <span class="px-2.5 py-1 text-xs font-semibold rounded-full bg-success text-success-foreground self-start sm:self-auto">
                Audited &amp; Reconciled
            </span>
        </div>
    @elseif($isAuditPending)
        <div class="rounded-xl border border-warning-border bg-warning-muted p-4 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
            <div class="flex items-center gap-3">
                <span class="material-symbols-rounded text-warning text-2xl">pending_actions</span>
                <div>
                    <div class="text-xs sm:text-sm font-bold text-foreground">Audit Shift Berjalan: Buku Besar Sinkron (Variance: Rp 0)</div>
                    <div class="text-xs text-muted-foreground mt-0.5">
                        {{ $glTrust['status_text'] ?? 'Shift kasir aktif belum ditutup' }} · Subledger POS dan Jurnal GL seimbang tanpa selisih transaksi.
                    </div>
                </div>
            </div>
            <span class="px-2.5 py-1 text-xs font-semibold rounded-full bg-warning text-warning-foreground self-start sm:self-auto">
                {{ $glTrust['status_badge'] ?? 'Audit Pending' }}
            </span>
        </div>
    @else
        <div class="rounded-xl border border-destructive-border bg-destructive-muted p-4 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
            <div class="flex items-center gap-3">
                <span class="material-symbols-rounded text-destructive text-2xl">warning</span>
                <div>
                    <div class="text-xs sm:text-sm font-bold text-destructive">GL Reconciliation Variance Detected</div>
                    <div class="text-xs text-muted-foreground mt-0.5">
                        Selisih Rp {{ number_format($glVariance, 0, ',', '.') }} antara POS Subledger (Rp {{ number_format($glTrust['net_pos_sales'] ?? 0, 0, ',', '.') }}) dan Jurnal GL (Rp {{ number_format($glTrust['sales_journal_balance'] ?? 0, 0, ',', '.') }}). Periksa unposted batches!
                    </div>
                </div>
            </div>
            <span class="px-2.5 py-1 text-xs font-semibold rounded-full bg-destructive text-destructive-foreground self-start sm:self-auto">
                Attention Required
            </span>
        </div>
    @endif
@endif

