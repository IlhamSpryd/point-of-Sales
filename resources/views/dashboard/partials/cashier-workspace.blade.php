{{-- ========================================================================= --}}
{{-- ARCHETYPE VIEW 1: CASHIER WORKSPACE (Frontline Cashier Shift & Register)  --}}
{{-- ========================================================================= --}}
@if($activeArchetype === 'cashier_workspace')
    <section class="space-y-6" aria-label="Cashier Workspace View">
        {{-- Cashier Shift Banner --}}
        <div class="card-surface rounded-2xl border border-border p-5 sm:p-6 shadow-xs flex flex-col md:flex-row md:items-center justify-between gap-4">
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 rounded-xl bg-primary/10 text-primary flex items-center justify-center shrink-0">
                    <span class="material-symbols-rounded text-2xl">account_circle</span>
                </div>
                <div>
                    <div class="text-xs font-semibold text-muted-foreground uppercase tracking-wider">Active Shift Summary</div>
                    <div class="text-lg sm:text-xl font-bold text-foreground">
                        {{ $cashierData['cashier_name'] ?? auth()->user()?->name }} · {{ $cashierData['active_store_name'] ?? 'Front Register' }}
                    </div>
                    <div class="text-xs text-muted-foreground mt-0.5">
                        @if(!empty($cashierData['active_shift']['start_time']))
                            Shift started at <span class="font-semibold text-foreground">{{ $cashierData['active_shift']['start_time'] }}</span> with starting float of <span class="font-semibold text-foreground">Rp {{ number_format($cashierData['active_shift']['starting_cash'], 0, ',', '.') }}</span>
                        @else
                            No open register session detected. Launch POS Terminal to open drawer.
                        @endif
                    </div>
                </div>
            </div>
            <a href="{{ route('transaction.create') }}" wire:navigate
               class="inline-flex items-center justify-center gap-2 px-5 py-3 rounded-xl text-sm font-bold text-primary-foreground bg-primary hover:opacity-90 shadow-xs active:scale-95 transition-all">
                <span class="material-symbols-rounded text-[18px]">point_of_sale</span>
                <span>Open POS Register</span>
            </a>
        </div>

        {{-- Cashier Personal KPIs --}}
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 sm:gap-6">
            <x-erp.stat-card
                label="My Today's Sales"
                hint="Personal sales in shift"
                :value="'Rp ' . number_format($cashierData['my_today_sales'] ?? 0, 0, ',', '.')"
                delta="+0.0%"
                delta-variant="positive"
            />
            <x-erp.stat-card
                label="Shift Transactions"
                hint="Tickets processed by me"
                :value="number_format($cashierData['my_today_tx_count'] ?? 0, 0, ',', '.')"
                delta="+0.0%"
                delta-variant="positive"
            />
            <x-erp.stat-card
                label="Expected Drawer Cash"
                hint="Float + Cash sales"
                :value="'Rp ' . number_format($cashierData['shift_expected_cash'] ?? 0, 0, ',', '.')"
                delta="+0.0%"
                delta-variant="positive"
            />
        </div>

        {{-- Cashier Shift Operations Row --}}
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            {{-- Cashier Payment Breakdown --}}
            <div class="card-surface rounded-xl border border-border p-5 shadow-xs">
                <div class="text-sm font-bold text-foreground mb-4">Shift Payment Collection</div>
                <div class="space-y-3">
                    <div class="flex items-center justify-between p-3 rounded-lg bg-muted/40 border border-border/50 text-xs">
                        <span class="font-medium text-foreground">Cash (Tunai)</span>
                        <span class="font-bold tabular-nums text-foreground">Rp {{ number_format($cashierData['payment_breakdown']['cash'] ?? 0, 0, ',', '.') }}</span>
                    </div>
                    <div class="flex items-center justify-between p-3 rounded-lg bg-muted/40 border border-border/50 text-xs">
                        <span class="font-medium text-foreground">QRIS Dynamic</span>
                        <span class="font-bold tabular-nums text-foreground">Rp {{ number_format($cashierData['payment_breakdown']['qris'] ?? 0, 0, ',', '.') }}</span>
                    </div>
                    <div class="flex items-center justify-between p-3 rounded-lg bg-muted/40 border border-border/50 text-xs">
                        <span class="font-medium text-foreground">Debit / Credit Card</span>
                        <span class="font-bold tabular-nums text-foreground">Rp {{ number_format($cashierData['payment_breakdown']['card'] ?? 0, 0, ',', '.') }}</span>
                    </div>
                    <div class="flex items-center justify-between p-3 rounded-lg bg-muted/40 border border-border/50 text-xs">
                        <span class="font-medium text-foreground">E-Wallet (GoPay/OVO/ShopeePay)</span>
                        <span class="font-bold tabular-nums text-foreground">Rp {{ number_format($cashierData['payment_breakdown']['ewallet'] ?? 0, 0, ',', '.') }}</span>
                    </div>
                </div>
            </div>

            {{-- Cashier Recent Orders --}}
            <div class="card-surface rounded-xl border border-border p-5 shadow-xs">
                <div class="flex items-center justify-between mb-4">
                    <div class="text-sm font-bold text-foreground">My Recent Transactions</div>
                    <a href="{{ route('orders.index') }}" wire:navigate class="text-xs font-semibold text-muted-foreground hover:text-foreground">View all</a>
                </div>
                <div class="divide-y divide-border/60">
                    @forelse($cashierData['recent_orders'] ?? [] as $shiftOrder)
                        <div class="py-2.5 flex items-center justify-between text-xs">
                            <div>
                                <div class="font-bold text-foreground">#{{ $shiftOrder['invoice_no'] }}</div>
                                <div class="text-[11px] text-muted-foreground">{{ $shiftOrder['time'] }} · {{ strtoupper($shiftOrder['payment_method']) }}</div>
                            </div>
                            <div class="text-right">
                                <div class="font-bold text-foreground tabular-nums">Rp {{ number_format($shiftOrder['amount'], 0, ',', '.') }}</div>
                                <span class="text-[10px] text-success font-semibold">Completed</span>
                            </div>
                        </div>
                    @empty
                        <div class="py-6 text-center text-xs text-muted-foreground">No transactions recorded in this shift yet.</div>
                    @endforelse
                </div>
            </div>
        </div>
    </section>
@endif

