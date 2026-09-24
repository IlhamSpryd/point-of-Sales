@php
    // CATATAN ARSITEKTUR (Backend): ini ESTIMASI tampilan yang meniru TransactionService::calculateOrderTotals().
    $taxRate = (float) config('pos.tax_rate', 0.11);
    $tax = (int) round($subtotal * $taxRate);
    $raw = $subtotal + $tax;
    $step = max(1, (int) config('pos.rounding_value', 100));
    $total = (int) match (config('pos.rounding_behavior', 'ROUND_NEAREST')) {
        'ROUND_NEAREST' => round($raw / $step) * $step,
        'ROUND_UP'      => ceil($raw / $step) * $step,
        'ROUND_DOWN'    => floor($raw / $step) * $step,
        default         => round($raw),
    };
    $methods = [
        ['qris',    'qr_code_2',              'QRIS',     'Scan dengan aplikasi bank atau e-wallet apa pun'],
        ['ewallet', 'account_balance_wallet', 'E-Wallet', 'GoPay atau ShopeePay'],
        ['cash',    'payments',               'Bayar Tunai', 'Bayar ke kasir dan tunjukkan kode pesanan'],
    ];
@endphp

<x-customer-layout :back-url="route('customer.cart.index')">
    <x-slot:title>Pembayaran</x-slot:title>

    <div x-data="{ method: 'qris', submitting: false }">
        <form action="{{ route('customer.checkout.store') }}" method="POST" @submit="submitting = true">
            @csrf
            <input type="hidden" name="_idempotency_key" value="{{ $idempotencyKey }}">
            <input type="hidden" name="payment_method" value="qris" :value="method">



            @if (session('error'))
                <div role="alert" class="mb-5 flex items-start gap-2 rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-medium text-rose-700">
                    <span class="material-symbols-rounded text-[18px] mt-0.5">error</span>
                    <span>{{ session('error') }}</span>
                </div>
            @endif

            {{-- Ringkasan pesanan --}}
            <section class="card-surface p-5 mb-5" aria-labelledby="ringkasan">
                <h2 id="ringkasan" class="text-[12px] font-bold uppercase tracking-wider text-yovel-muted mb-2">Ringkasan Pesanan</h2>
                <ul class="divide-y divide-yovel-border">
                    @foreach ($items as $item)
                        <li class="py-3 flex items-start justify-between gap-4">
                            <div class="min-w-0">
                                <p class="text-[14px] font-semibold">{{ $item['qty'] }}× {{ $item['product_name'] }}</p>
                                @if (! empty($item['options']))
                                    <p class="text-[12px] text-yovel-muted mt-0.5">{{ collect($item['options'])->pluck('modifier_name')->filter()->implode(' · ') }}</p>
                                @endif
                                @if (! empty($item['notes']))
                                    <p class="text-[12px] italic text-yovel-muted mt-0.5">Catatan: {{ $item['notes'] }}</p>
                                @endif
                            </div>
                            <span class="text-[14px] font-semibold tabular-nums shrink-0">Rp {{ number_format($item['subtotal'], 0, ',', '.') }}</span>
                        </li>
                    @endforeach
                </ul>

                <dl class="mt-2 pt-3 border-t border-yovel-border text-[14px] space-y-1.5">
                    <div class="flex justify-between text-yovel-muted"><dt>Subtotal</dt><dd class="tabular-nums">Rp {{ number_format($subtotal, 0, ',', '.') }}</dd></div>
                    <div class="flex justify-between text-yovel-muted"><dt>Pajak ({{ rtrim(rtrim(number_format($taxRate * 100, 1), '0'), '.') }}%)</dt><dd class="tabular-nums">Rp {{ number_format($tax, 0, ',', '.') }}</dd></div>
                    <div class="flex justify-between text-[17px] font-extrabold pt-1"><dt>Total Tagihan</dt><dd class="tabular-nums">Rp {{ number_format($total, 0, ',', '.') }}</dd></div>
                </dl>
            </section>

            {{-- Member / Loyalty --}}
            <section aria-labelledby="loyalty" class="mb-5">
                <h2 id="loyalty" class="text-[12px] font-bold uppercase tracking-wider text-yovel-muted mb-3">Member & Loyalty (Opsional)</h2>
                <div class="card-surface p-4">
                    <label for="customer_phone" class="block text-[13px] font-semibold text-gray-700 mb-1.5">Nomor Handphone</label>
                    <input type="tel" name="customer_phone" id="customer_phone" value="{{ old('customer_phone') }}"
                           placeholder="Contoh: 08123456789"
                           class="w-full rounded-xl border-yovel-border bg-white px-4 py-2.5 text-[14px] focus:border-yovel-ink focus:ring-1 focus:ring-yovel-ink outline-none transition-all" />
                    <p class="mt-2 text-[11px] text-yovel-muted">Masukkan nomor HP yang terdaftar untuk mendapatkan Poin Loyalty dari transaksi ini.</p>
                </div>
            </section>

            {{-- Metode pembayaran: Button-Box --}}
            <section aria-labelledby="metode">
                <h2 id="metode" class="text-[12px] font-bold uppercase tracking-wider text-yovel-muted mb-3">Metode Pembayaran</h2>
                <div role="radiogroup" aria-labelledby="metode" class="grid grid-cols-1 gap-3">
                    @foreach ($methods as [$value, $icon, $label, $hint])
                        <button type="button" role="radio"
                                :aria-checked="(method === '{{ $value }}').toString()"
                                @click="method = '{{ $value }}'"
                                :class="method === '{{ $value }}' ? 'border-yovel-ink ring-1 ring-yovel-ink bg-white' : 'border-yovel-border bg-white hover:bg-yovel-bg'"
                                class="w-full min-h-16 flex items-center gap-4 rounded-2xl border-[1.5px] px-4 py-3 text-left transition-all duration-200 active:scale-[0.98] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-yovel-ink">
                            <span class="w-11 h-11 rounded-xl bg-yovel-surface flex items-center justify-center shrink-0">
                                <span class="material-symbols-rounded text-[22px]">{{ $icon }}</span>
                            </span>
                            <span class="flex-1 min-w-0">
                                <span class="block text-[15px] font-bold">{{ $label }}</span>
                                <span class="block text-[13px] text-yovel-muted mt-0.5">{{ $hint }}</span>
                            </span>
                            <span class="w-6 h-6 rounded-full border-[1.5px] flex items-center justify-center shrink-0 transition-all duration-200"
                                  :class="method === '{{ $value }}' ? 'bg-yovel-ink border-yovel-ink text-white' : 'border-primary-300 text-transparent'">
                                <span class="material-symbols-rounded text-[16px]">check</span>
                            </span>
                        </button>
                    @endforeach
                </div>
                <p class="mt-3 flex items-center gap-1.5 text-[11px] text-yovel-muted">
                    <span class="material-symbols-rounded text-[14px]">lock</span> Pembayaran diproses aman melalui Midtrans.
                </p>
            </section>

            <div class="h-40" aria-hidden="true"></div>

            {{-- Bar CTA (fixed) --}}
            <div class="fixed inset-x-0 bottom-0 z-30 mx-auto max-w-md bg-white/90 backdrop-blur-xl border-t border-yovel-border sm:border-x">
                <div class="px-4 pt-4 pb-safe">
                    <button type="submit" :disabled="submitting"
                            class="w-full flex items-center justify-between rounded-2xl bg-yovel-ink text-white px-5 min-h-14 font-semibold shadow-lg hover:bg-black active:scale-[0.98] disabled:opacity-60 disabled:cursor-not-allowed transition-all duration-200 focus-visible:ring-2 focus-visible:ring-yovel-ink focus-visible:ring-offset-2">
                        <span class="flex items-center gap-2 text-[15px]">
                            <span x-show="submitting" x-cloak class="spinner" aria-hidden="true"></span>
                            <span x-text="submitting ? 'Memproses…' : 'Bayar Sekarang'"></span>
                        </span>
                        <span class="tabular-nums text-[15px]">Rp {{ number_format($total, 0, ',', '.') }}</span>
                    </button>
                </div>
            </div>
        </form>
    </div>
</x-customer-layout>
