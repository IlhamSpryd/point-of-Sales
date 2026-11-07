@php
    $methods = [
        ['qris',    'qr_code_2',              'QRIS',     'Scan with supported mobile banking or e-wallet application'],
        ['ewallet', 'account_balance_wallet', 'E-Wallet', 'GoPay or ShopeePay'],
        ['cash',    'payments',               'Cash Payment', 'Pay at the counter and present your order number'],
    ];
@endphp

<x-customer-layout :back-url="route('customer.cart.index')">
    <x-slot:title>Checkout</x-slot:title>

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
                <h2 id="ringkasan" class="text-[12px] font-bold uppercase tracking-wider text-muted-foreground mb-2">Order Summary</h2>
                <ul class="divide-y divide-border">
                    @foreach ($items as $item)
                        <li class="py-3 flex items-start justify-between gap-4">
                            <div class="min-w-0">
                                <p class="text-[14px] font-semibold">{{ $item['qty'] }}× {{ $item['product_name'] }}</p>
                                @if (! empty($item['options']))
                                    <p class="text-[12px] text-muted-foreground mt-0.5">{{ collect($item['options'])->pluck('modifier_name')->filter()->implode(' · ') }}</p>
                                @endif
                                @if (! empty($item['notes']))
                                    <p class="text-[12px] italic text-muted-foreground mt-0.5">Note: {{ $item['notes'] }}</p>
                                @endif
                            </div>
                            <span class="text-[14px] font-semibold tabular-nums shrink-0">Rp {{ number_format($item['subtotal'], 0, ',', '.') }}</span>
                        </li>
                    @endforeach
                </ul>

                <dl class="mt-2 pt-3 border-t border-border text-[14px] space-y-1.5">
                    <div class="flex justify-between text-muted-foreground"><dt>Subtotal</dt><dd class="tabular-nums">Rp {{ number_format($subtotal, 0, ',', '.') }}</dd></div>
                    <div class="flex justify-between text-muted-foreground"><dt>Tax ({{ rtrim(rtrim(number_format(($taxRate ?? 0.11) * 100, 1), '0'), '.') }}%)</dt><dd class="tabular-nums">Rp {{ number_format($tax, 0, ',', '.') }}</dd></div>
                    <div class="flex justify-between text-[17px] font-extrabold pt-1"><dt>Total Amount</dt><dd class="tabular-nums">Rp {{ number_format($total, 0, ',', '.') }}</dd></div>
                </dl>
            </section>

            {{-- Member / Loyalty --}}
            <section aria-labelledby="loyalty" class="mb-5">
                <h2 id="loyalty" class="text-[12px] font-bold uppercase tracking-wider text-muted-foreground mb-3">Member & Loyalty (Optional)</h2>
                <div class="card-surface p-4">
                    <label for="customer_phone" class="block text-[13px] font-semibold text-gray-700 mb-1.5">Mobile Number</label>
                    <input type="tel" name="customer_phone" id="customer_phone" value="{{ old('customer_phone') }}"
                           placeholder="e.g. 08123456789"
                           class="w-full rounded-xl border-border bg-white px-4 py-2.5 text-[14px] focus:border-primary focus:ring-1 focus:ring-ring outline-none transition-all" />
                    <p class="mt-2 text-[11px] text-muted-foreground">Enter registered mobile number to collect loyalty points for this order.</p>
                </div>
            </section>

            {{-- Metode pembayaran: Button-Box --}}
            <section aria-labelledby="metode">
                <h2 id="metode" class="text-[12px] font-bold uppercase tracking-wider text-muted-foreground mb-3">Payment Method</h2>
                <div role="radiogroup" aria-labelledby="metode" class="grid grid-cols-1 gap-3">
                    @foreach ($methods as [$value, $icon, $label, $hint])
                        <button type="button" role="radio"
                                :aria-checked="(method === '{{ $value }}').toString()"
                                @click="method = '{{ $value }}'"
                                :class="method === '{{ $value }}' ? 'border-foreground ring-1 ring-ring bg-white' : 'border-border bg-white hover:bg-card'"
                                class="w-full min-h-16 flex items-center gap-4 rounded-2xl border-[1.5px] px-4 py-3 text-left transition-all duration-200 active:scale-[0.98] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring">
                            <span class="w-11 h-11 rounded-xl bg-secondary flex items-center justify-center shrink-0">
                                <span class="material-symbols-rounded text-[22px]">{{ $icon }}</span>
                            </span>
                            <span class="flex-1 min-w-0">
                                <span class="block text-[15px] font-bold">{{ $label }}</span>
                                <span class="block text-[13px] text-muted-foreground mt-0.5">{{ $hint }}</span>
                            </span>
                            <span class="w-6 h-6 rounded-full border-[1.5px] flex items-center justify-center shrink-0 transition-all duration-200"
                                  :class="method === '{{ $value }}' ? 'bg-primary border-foreground text-white' : 'border-primary-300 text-transparent'">
                                <span class="material-symbols-rounded text-[16px]">check</span>
                            </span>
                        </button>
                    @endforeach
                </div>
                <p class="mt-3 flex items-center gap-1.5 text-[11px] text-muted-foreground">
                    <span class="material-symbols-rounded text-[14px]">lock</span> Payments securely processed via Midtrans.
                </p>
            </section>

            <div class="h-40" aria-hidden="true"></div>

            {{-- Bar CTA (fixed) --}}
            <div class="fixed inset-x-0 bottom-0 z-30 mx-auto max-w-md bg-white/90 backdrop-blur-xl border-t border-border sm:border-x">
                <div class="px-4 pt-4 pb-safe">
                    <button type="submit" :disabled="submitting"
                            class="w-full flex items-center justify-between rounded-2xl bg-primary text-white px-5 min-h-14 font-semibold shadow-lg hover:bg-primary/90 active:scale-[0.98] disabled:opacity-60 disabled:cursor-not-allowed transition-all duration-200 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2">
                        <span class="flex items-center gap-2 text-[15px]">
                            <span x-show="submitting" x-cloak class="spinner" aria-hidden="true"></span>
                            <span x-text="submitting ? 'Processing…' : 'Pay Now'"></span>
                        </span>
                        <span class="tabular-nums text-[15px]">Rp {{ number_format($total, 0, ',', '.') }}</span>
                    </button>
                </div>
            </div>
        </form>
    </div>
</x-customer-layout>
