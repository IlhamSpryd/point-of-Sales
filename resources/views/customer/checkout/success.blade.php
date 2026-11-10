@php
    $menuUrl = $order->table ? route('customer.menu.index', ['token' => $order->table->secure_token]) : null;
    $snapSrc = config('services.midtrans.is_production')
        ? 'https://app.midtrans.com/snap/snap.js'
        : 'https://app.sandbox.midtrans.com/snap/snap.js';
    
    // Pastikan snap_token ditarik dari database order jika ada
    $snapToken =$order->snap_token ?? null;
    
    $config = [
        'status'        => $order->order_status->value,
        'token'         => $snapToken,
        'paymentMethod' => $order->payment_method->value ?? 'cash',
        'statusUrl'     => route('customer.checkout.status', ['orderCode' => $order->order_code]),
    ];
@endphp

<x-customer-layout>
    <x-slot:title>Order Status</x-slot:title>

    @if ($snapToken)
        <script src="{{ $snapSrc }}" data-client-key="{{ config('services.midtrans.client_key') }}"></script>
    @endif

    <div x-data="orderStatus(@js($config))" class="pb-8">

        <section class="card-surface flex flex-col items-center px-6 py-10 text-center mt-6" aria-live="polite">
            <div class="mb-5 flex h-20 w-20 items-center justify-center rounded-full bg-secondary"
                 :class="isPending ? 'animate-pulse' : ''">
                <span class="material-symbols-rounded text-[40px]" :class="view.critical ? 'text-rose-600' : ''" x-text="view.icon"></span>
            </div>
            <h1 class="text-[20px] font-bold tracking-tight" x-text="view.title"></h1>
            <p class="mt-2 max-w-xs text-[14px] text-muted-foreground" x-text="view.text"></p>
            
            {{-- Tombol Snap Fallback (Pastikan ini selalu ada di HTML jika token tersedia) --}}
            @if ($snapToken)
                <button type="button" x-show="isPending" @click="pay()" x-cloak
                        class="mt-6 flex min-h-12 w-full max-w-[200px] items-center justify-center gap-2 rounded-xl bg-primary font-semibold text-white shadow-md transition-all hover:bg-primary/90 active:scale-[0.98]">
                    <span class="material-symbols-rounded text-[20px]">payments</span> <span class="text-[16px]">Pay Now</span>
                </button>
            @endif

            {{-- F-11 ETA --}}
            <div x-show="status === 'paid' && eta > 0" x-cloak class="mt-4 inline-flex items-center gap-1.5 rounded-md border border-amber-200 bg-amber-50 px-2.5 py-1 text-xs font-medium text-amber-800">
                <span class="material-symbols-rounded text-[16px]">timer</span>
                <span>Estimated ready in <span x-text="eta"></span> minutes</span>
            </div>
        </section>

        <section class="card-surface mt-4 p-5">
            <dl class="space-y-3 text-[14px]">
                <div class="flex justify-between gap-4">
                    <dt class="text-muted-foreground">Order Number</dt>
                    <dd class="font-sans font-bold">{{ $order->order_code }}</dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-muted-foreground">Table</dt>
                    <dd class="font-semibold">{{ $order->table?->table_name ?? '-' }}</dd>
                </div>
                <div class="flex justify-between gap-4 border-t border-border pt-3">
                    <dt class="font-semibold">Total Amount</dt>
                    <dd class="text-[17px] font-extrabold tabular-nums">Rp {{ number_format($order->order_amount, 0, ',', '.') }}</dd>
                </div>
            </dl>
        </section>

        <div class="mt-5 space-y-3">
            @if ($menuUrl)
                <a href="{{ $menuUrl }}" x-show="!isPending" x-cloak
                   class="flex min-h-14 w-full items-center justify-center gap-2 rounded-2xl border border-border bg-white font-semibold shadow-sm transition-all hover:bg-card active:scale-[0.98]">
                    <span class="material-symbols-rounded text-[20px]">restaurant_menu</span> <span class="text-[16px]">Back to Menu</span>
                </a>
                
                {{-- PATCH FOR F-10: Connect reorder() UI --}}
                <form action="{{ route('customer.checkout.reorder') }}" method="POST" x-show="!isPending" x-cloak>
                    @csrf
                    <button type="submit"
                            class="flex min-h-14 w-full items-center justify-center gap-2 rounded-2xl bg-secondary font-semibold shadow-sm transition-all hover:bg-card active:scale-[0.98]">
                        <span class="material-symbols-rounded text-[20px]">replay</span> <span class="text-[16px]">Reorder Items</span>
                    </button>
                </form>
            @endif
        </div>
    </div>

    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.data('orderStatus', (cfg) => ({
                status: cfg.status,
                eta: 0,
                startedAt: Date.now(),
                timer: null,

                states: {
                    pending:   { icon: 'hourglass_top', title: 'Awaiting Payment',   text: cfg.paymentMethod === 'cash' ? 'Please present this order code to the cashier to pay.' : 'Complete your payment to start order preparation.' },
                    paid:      { icon: 'check_circle',  title: 'Payment Received',   text: 'Your order has been routed to the kitchen queue.' },
                    completed: { icon: 'task_alt',      title: 'Order Completed',    text: 'Enjoy your meal!' },
                    expired:   { icon: 'timer_off',     title: 'Payment Expired',    text: 'Payment window timed out.', critical: true },
                    failed:    { icon: 'error',         title: 'Payment Failed',     text: 'Please reorder your items.', critical: true },
                    cancelled: { icon: 'cancel',        title: 'Order Cancelled',    text: 'This order has been cancelled.', critical: true },
                },

                get view() { return this.states[this.status] ?? this.states.pending; },
                get isPending() { return this.status === 'pending'; },

                init() {
                    // Start polling immediately if paid to get ETA
                    if (this.isPending || this.status === 'paid') {
                        this.schedule();
                    }
                    
                    if (!this.isPending) return;
                    
                    // Coba panggil snap otomatis dengan sedikit delay agar script Midtrans siap
                    if (cfg.token) {
                        setTimeout(() => this.pay(), 1000);
                    }
                    
                    this.schedule();
                    document.addEventListener('visibilitychange', () => {
                        if (!document.hidden && this.isPending) this.refresh();
                    });
                },

                schedule() {
                    clearTimeout(this.timer);
                    if (this.status === 'completed' || this.status === 'failed' || this.status === 'cancelled' || this.status === 'expired') return;
                    this.timer = setTimeout(async () => {
                        await this.refresh();
                        this.schedule();
                    }, 4000);
                },

                async refresh() {
                    try {
                        const res = await fetch(cfg.statusUrl, { headers: { 'Accept': 'application/json' } });
                        if (res.ok) {
                            const data = await res.json();
                            this.status = data.order_status;
                            this.eta = data.eta_minutes || 0;
                        }
                    } catch (e) {
                        // Jaringan putus: abaikan
                    }
                },

                pay() {
                    if (typeof window.snap === 'undefined' || !cfg.token) {
                        this.$dispatch('toast', { message: 'Connecting to payment gateway...' });
                        return;
                    }
                    window.snap.pay(cfg.token, {
                        onSuccess: () => { this.status = 'paid'; this.refresh(); },
                        onPending: () => this.refresh(),
                        onError: () => this.$dispatch('toast', { message: 'Payment was unsuccessful.' }),
                        onClose: () => { /* User tutup modal */ },
                    });
                },
            }));
        });
    </script>
</x-customer-layout>
