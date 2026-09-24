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
    <x-slot:title>Status Pesanan</x-slot:title>

    @if ($snapToken)
        <script src="{{ $snapSrc }}" data-client-key="{{ config('services.midtrans.client_key') }}"></script>
    @endif

    <div x-data="orderStatus(@js($config))" class="pb-8">

        <section class="card-surface flex flex-col items-center px-6 py-10 text-center mt-6" aria-live="polite">
            <div class="mb-5 flex h-20 w-20 items-center justify-center rounded-full bg-yovel-surface"
                 :class="isPending ? 'animate-pulse' : ''">
                <span class="material-symbols-rounded text-[40px]" :class="view.critical ? 'text-rose-600' : ''" x-text="view.icon"></span>
            </div>
            <h1 class="text-[20px] font-bold tracking-tight" x-text="view.title"></h1>
            <p class="mt-2 max-w-xs text-[14px] text-yovel-muted" x-text="view.text"></p>
            
            {{-- Tombol Snap Fallback (Pastikan ini selalu ada di HTML jika token tersedia) --}}
            @if ($snapToken)
                <button type="button" x-show="isPending" @click="pay()" x-cloak
                        class="mt-6 flex min-h-12 w-full max-w-[200px] items-center justify-center gap-2 rounded-xl bg-yovel-ink font-semibold text-white shadow-md transition-all hover:bg-black active:scale-[0.98]">
                    <span class="material-symbols-rounded text-[18px]">payments</span> <span class="text-[15px]">Bayar Sekarang</span>
                </button>
            @endif

            {{-- F-11 ETA --}}
            <div x-show="status === 'paid' && eta > 0" x-cloak class="mt-4 inline-flex items-center gap-1.5 rounded-full bg-orange-100 px-3 py-1.5 text-sm font-medium text-orange-800">
                <span class="material-symbols-rounded text-[16px]">timer</span>
                <span>Estimasi siap dalam <span x-text="eta"></span> menit</span>
            </div>
        </section>

        <section class="card-surface mt-4 p-5">
            <dl class="space-y-3 text-[14px]">
                <div class="flex justify-between gap-4">
                    <dt class="text-yovel-muted">Nomor pesanan</dt>
                    <dd class="font-mono font-bold">{{ $order->order_code }}</dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-yovel-muted">Meja</dt>
                    <dd class="font-semibold">{{ $order->table?->table_name ?? '-' }}</dd>
                </div>
                <div class="flex justify-between gap-4 border-t border-yovel-border pt-3">
                    <dt class="font-semibold">Total Tagihan</dt>
                    <dd class="text-[17px] font-extrabold tabular-nums">Rp {{ number_format($order->order_amount, 0, ',', '.') }}</dd>
                </div>
            </dl>
        </section>

        <div class="mt-5 space-y-3">
            @if ($menuUrl)
                <a href="{{ $menuUrl }}" x-show="!isPending" x-cloak
                   class="flex min-h-14 w-full items-center justify-center gap-2 rounded-2xl border border-yovel-border bg-white font-semibold shadow-sm transition-all hover:bg-yovel-bg active:scale-[0.98]">
                    <span class="material-symbols-rounded text-[20px]">restaurant_menu</span> <span class="text-[15px]">Kembali ke Menu</span>
                </a>
                
                {{-- PATCH FOR F-10: Connect reorder() UI --}}
                <form action="{{ route('customer.checkout.reorder') }}" method="POST" x-show="!isPending" x-cloak>
                    @csrf
                    <button type="submit"
                            class="flex min-h-14 w-full items-center justify-center gap-2 rounded-2xl bg-yovel-surface font-semibold shadow-sm transition-all hover:bg-yovel-bg active:scale-[0.98]">
                        <span class="material-symbols-rounded text-[20px]">replay</span> <span class="text-[15px]">Pesan Ulang (Restore Cart)</span>
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
                    pending:   { icon: 'hourglass_top', title: 'Menunggu pembayaran',   text: cfg.paymentMethod === 'cash' ? 'Silakan tunjukkan QR/kode ini ke kasir untuk membayar tunai.' : 'Selesaikan pembayaran agar pesanan diproses.' },
                    paid:      { icon: 'check_circle',  title: 'Pembayaran diterima',   text: 'Pesanan masuk antrean dapur.' },
                    completed: { icon: 'task_alt',      title: 'Pesanan selesai',       text: 'Selamat menikmati.' },
                    expired:   { icon: 'timer_off',     title: 'Kedaluwarsa',           text: 'Pembayaran lewat batas waktu.', critical: true },
                    failed:    { icon: 'error',         title: 'Gagal diproses',        text: 'Silakan pesan ulang.', critical: true },
                    cancelled: { icon: 'cancel',        title: 'Dibatalkan',            text: 'Pesanan ini dibatalkan.', critical: true },
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
                        this.$dispatch('toast', { message: 'Menghubungkan ke Midtrans...' });
                        return;
                    }
                    window.snap.pay(cfg.token, {
                        onSuccess: () => { this.status = 'paid'; this.refresh(); },
                        onPending: () => this.refresh(),
                        onError: () => this.$dispatch('toast', { message: 'Pembayaran gagal.' }),
                        onClose: () => { /* User tutup modal */ },
                    });
                },
            }));
        });
    </script>
</x-customer-layout>
