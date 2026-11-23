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

{{-- Plan vs Actual Attainment Banner (KPI-PLN-01) --}}
@if(isset($executiveData['planVsActual']))
    @php
        $pva = $executiveData['planVsActual'];
        $pvaStatus = $pva['status'] ?? 'no_target';
        $actualRev = $pva['actual_revenue'] ?? (float) ($pva['actual_net_sales'] ?? 0);
        $targetRev = $pva['target_revenue'] ?? (float) ($pva['target_net_sales'] ?? 0);
        $projRev = $pva['projected_revenue'] ?? (float) ($pva['run_rate_projection'] ?? 0);
        $attainPct = (float) ($pva['attainment_pct'] ?? 0);
    @endphp
    <div class="card-surface rounded-xl border border-border p-4 sm:p-5 shadow-xs flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div class="space-y-1">
            <div class="text-xs font-bold uppercase tracking-wider text-muted-foreground">Strategic Sales Target &amp; Attainment (KPI-PLN-01)</div>
            <div class="flex items-baseline gap-3">
                <span class="text-2xl sm:text-3xl font-extrabold text-foreground tabular-nums">{{ $attainPct }}%</span>
                <span class="text-xs font-semibold px-2 py-0.5 rounded-full {{ $pvaStatus === 'exceeded' ? 'bg-success-muted text-success border border-success-border' : ($pvaStatus === 'on_track' ? 'bg-primary/10 text-primary border border-primary/20' : 'bg-destructive-muted text-destructive border border-destructive-border') }}">
                    {{ strtoupper(str_replace('_', ' ', $pvaStatus)) }}
                </span>
            </div>
            <div class="text-xs text-muted-foreground tabular-nums">
                Actual Net Sales: <strong class="text-foreground">Rp {{ number_format($actualRev, 0, ',', '.') }}</strong> of Target <strong class="text-foreground">Rp {{ number_format($targetRev, 0, ',', '.') }}</strong>
            </div>
        </div>

        <div class="flex flex-col sm:flex-row items-start sm:items-center gap-3">
            <div class="w-full sm:w-64 md:w-80 space-y-2">
                <div class="flex items-center justify-between text-xs">
                    <span class="text-muted-foreground">Month Run-Rate Projection</span>
                    <span class="font-bold text-foreground tabular-nums">Rp {{ number_format($projRev, 0, ',', '.') }}</span>
                </div>
                <div class="w-full bg-muted rounded-full h-2.5 overflow-hidden">
                    <div class="bg-primary h-full transition-all duration-300 rounded-full" style="width: {{ min(100, $attainPct) }}%"></div>
                </div>
            </div>

            @if(auth()->user()?->role?->name === 'Owner' || (bool) (auth()->user()?->role?->is_admin ?? false) || auth()->user()?->hasPermission('*') || auth()->user()?->hasPermission('stores.manage'))
                <button type="button"
                        @click="openStoreTargetModal()"
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold text-foreground bg-secondary hover:bg-secondary/80 border border-border shadow-2xs transition-colors shrink-0 cursor-pointer">
                    <span class="material-symbols-rounded text-sm text-foreground">edit_calendar</span>
                    <span>Atur Target</span>
                </button>
            @endif
        </div>
    </div>
@endif

