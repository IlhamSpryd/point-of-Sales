{{-- ========================================================================= --}}
{{-- ARCHETYPE VIEW 2: BRANCH OPERATIONS (Store Manager & Supervisor Control)  --}}
{{-- ========================================================================= --}}
@if($activeArchetype === 'branch_ops')
    <section class="space-y-6" aria-label="Branch Operations Control View">
        {{-- Store Status Header --}}
        <div class="card-surface rounded-2xl border border-border p-5 shadow-xs flex flex-col md:flex-row md:items-center justify-between gap-4">
            <div>
                <div class="text-xs font-bold text-primary uppercase tracking-wider">Branch Operational Command</div>
                <div class="text-xl sm:text-2xl font-extrabold text-foreground mt-0.5">
                    {{ $managerData['store']['name'] ?? $activeStoreName ?? 'Store Operations' }}
                </div>
                <p class="text-xs text-muted-foreground mt-0.5">
                    Real-time shift synchronization, drawer cash verification, and pending authorization queue.
                </p>
            </div>
            <div class="flex items-center gap-2">
                <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-bold bg-success-muted text-success border border-success-border">
                    <span class="w-2 h-2 rounded-full bg-success animate-pulse"></span> Terminal Online
                </span>
            </div>
        </div>

        {{-- Branch Core KPIs --}}
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 sm:gap-6">
            <x-erp.stat-card
                label="Today Branch Sales"
                hint="Net sales accrued today"
                :value="'Rp ' . number_format($managerData['todayStoreSales'] ?? 0, 0, ',', '.')"
                :delta="$todayDeltaFormatted ?? '+0.0%'"
                :delta-variant="$todayDeltaVariant ?? 'positive'"
            />
            <x-erp.stat-card
                label="Completed Transactions"
                hint="Total tickets closed"
                :value="number_format($managerData['todayTransactionCount'] ?? 0, 0, ',', '.')"
                :delta="$weekDeltaFormatted ?? '+0.0%'"
                :delta-variant="$weekDeltaVariant ?? 'positive'"
            />
            <x-erp.stat-card
                label="Active Drawer Cash"
                hint="Float + cash in drawer"
                :value="'Rp ' . number_format($managerData['activeDrawerCash'] ?? 0, 0, ',', '.')"
                delta="+0.0%"
                delta-variant="positive"
            />
        </div>

        {{-- Operational Approval Queue & In-Place Slide-Over Action Trigger --}}
        <div class="card-surface rounded-xl border border-border p-5 shadow-xs">
            <div class="flex items-center justify-between mb-4">
                <div class="flex items-center gap-2">
                    <span class="material-symbols-rounded text-warning text-xl">admin_panel_settings</span>
                    <h2 class="text-sm font-bold text-foreground">Operational Authorization Queue (Void / Discount / Variance)</h2>
                </div>
                <span class="text-xs font-semibold px-2 py-0.5 rounded-md bg-warning-muted text-warning border border-warning-border">
                    {{ count($managerData['approvalQueue'] ?? []) }} Pending
                </span>
            </div>

            <div class="divide-y divide-border/60">
                @forelse($managerData['approvalQueue'] ?? [] as $approvalItem)
                    <div class="py-3.5 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                        <div>
                            <div class="flex items-center gap-2">
                                <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase {{ $approvalItem['type'] === 'VOID' ? 'bg-destructive-muted text-destructive border border-destructive-border' : ($approvalItem['type'] === 'DISCOUNT_OVERRIDE' ? 'bg-warning-muted text-warning border border-warning-border' : 'bg-primary/10 text-primary border border-primary/20') }}">
                                    {{ $approvalItem['type'] }}
                                </span>
                                <span class="font-bold text-xs text-foreground">#{{ $approvalItem['ticket_no'] }}</span>
                                <span class="text-xs font-bold text-foreground tabular-nums">· Rp {{ number_format($approvalItem['amount'], 0, ',', '.') }}</span>
                            </div>
                            <div class="text-xs text-muted-foreground mt-1">
                                Cashier: <span class="font-semibold text-foreground">{{ $approvalItem['cashier_name'] }}</span> · Reason: <span class="italic text-foreground">"{{ $approvalItem['reason'] }}"</span> · {{ $approvalItem['created_at'] }}
                            </div>
                        </div>
                        <button type="button"
                                @click="openApprovalDrawer(@js($approvalItem))"
                                class="inline-flex items-center justify-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold bg-primary text-primary-foreground hover:opacity-90 shadow-2xs cursor-pointer transition-all shrink-0">
                            <span class="material-symbols-rounded text-sm">lock_open</span>
                            <span>Review &amp; Authorize</span>
                        </button>
                    </div>
                @empty
                    <div class="py-6 text-center text-xs text-muted-foreground">
                        No pending supervisor authorizations in queue. All drawer activities conform to policy.
                    </div>
                @endforelse
            </div>
        </div>

        {{-- Cashier Scorecard & Hourly Traffic Grid --}}
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            {{-- Cashier Scorecard --}}
            <div class="card-surface rounded-xl border border-border p-5 shadow-xs">
                <div class="text-sm font-bold text-foreground mb-4">Cashier Performance Scorecard</div>
                <div class="overflow-x-auto">
                    <table class="w-full text-xs text-left">
                        <thead class="border-b border-border/80 text-muted-foreground font-semibold">
                            <tr>
                                <th class="pb-2">Cashier</th>
                                <th class="pb-2 text-right">Transactions</th>
                                <th class="pb-2 text-right">Total Sales</th>
                                <th class="pb-2 text-right">Avg Basket</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border/60">
                            @forelse($managerData['cashierScorecard'] ?? [] as $score)
                                <tr class="hover:bg-muted/30">
                                    <td class="py-2.5 font-semibold text-foreground">{{ $score['name'] }}</td>
                                    <td class="py-2.5 text-right tabular-nums">{{ $score['transactions_count'] }} tx</td>
                                    <td class="py-2.5 text-right font-bold tabular-nums text-foreground">Rp {{ number_format($score['sales_total'], 0, ',', '.') }}</td>
                                    <td class="py-2.5 text-right tabular-nums text-muted-foreground">Rp {{ number_format($score['avg_basket'], 0, ',', '.') }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="py-4 text-center text-muted-foreground">No active cashier records found today.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- Hourly Traffic Table --}}
            <div class="card-surface rounded-xl border border-border p-5 shadow-xs">
                <div class="text-sm font-bold text-foreground mb-4">Hourly Customer Traffic Distribution</div>
                <div class="space-y-2">
                    @foreach($managerData['hourlyTraffic'] ?? [] as $slot)
                        <div class="flex items-center justify-between text-xs p-2 rounded-lg bg-muted/30 border border-border/40">
                            <div class="flex items-center gap-2">
                                <span class="material-symbols-rounded text-sm text-muted-foreground">schedule</span>
                                <span class="font-medium text-foreground">{{ $slot['hour'] }}</span>
                            </div>
                            <div class="flex items-center gap-4">
                                <span class="text-muted-foreground tabular-nums">{{ $slot['count'] }} customers</span>
                                <span class="font-bold text-foreground tabular-nums">Rp {{ number_format($slot['amount'], 0, ',', '.') }}</span>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </section>
@endif

