@php
    $currentTable = \App\Models\Table::find(session('current_table_id'));
    $menuUrl = $currentTable ? route('customer.menu.index', ['token' => $currentTable->secure_token]) : null;

    // CATATAN ARSITEKTUR (Backend): pajak & pembulatan di bawah hanyalah ESTIMASI tampilan yang meniru
    // TransactionService::calculateOrderTotals(). Idealnya CartController mengirim $taxAmount/$totalAmount.
    $config = [
        'lines' => collect($items)->map(fn ($i) => [
            'lineId'    => $i['line_id'],
            'name'      => $i['product_name'],
            'photo'     => ! empty($i['product_photo']) ? asset('storage/'.$i['product_photo']) : null,
            'qty'       => (int) $i['qty'],
            'unitPrice' => (int) $i['unit_price'],
            'notes'     => $i['notes'] ?? null,
            'options'   => collect($i['options'] ?? [])->map(fn ($o) => [
                'name'  => $o['modifier_name'] ?? ($o['name'] ?? ''),
                'extra' => (int) ($o['extra_price'] ?? 0),
            ])->values()->all(),
        ])->values()->all(),
        'taxRate'          => (float) config('pos.tax_rate', 0.11),
        'roundingBehavior' => config('pos.rounding_behavior', 'ROUND_NEAREST'),
        'roundingValue'    => max(1, (int) config('pos.rounding_value', 100)),
        'urlTemplate'      => route('customer.cart.update', ['lineId' => '__LINE__']),
        'checkoutUrl'      => route('customer.checkout.create'),
        'menuUrl'          => $menuUrl,
    ];
@endphp

<x-customer-layout :back-url="$menuUrl">
    <x-slot:title>Keranjang</x-slot:title>

    <div x-data="cartPage(@js($config))">

        {{-- Empty state --}}
        <div x-show="lines.length === 0" x-cloak
             class="card-surface flex flex-col items-center text-center px-6 py-16">
            <div class="w-16 h-16 rounded-3xl bg-yovel-surface flex items-center justify-center mb-4">
                <span class="material-symbols-rounded text-[32px] text-primary-300">shopping_bag</span>
            </div>
            <h1 class="text-[17px] font-bold">Keranjang masih kosong</h1>
            <p class="text-[14px] text-yovel-muted mt-1 mb-6">Pilih menu favorit Anda untuk mulai memesan.</p>
            <template x-if="menuUrl">
                <a :href="menuUrl" class="inline-flex items-center gap-2 rounded-2xl bg-yovel-ink text-white px-6 min-h-12 font-semibold active:scale-95 hover:bg-black transition-all duration-200">
                    <span class="material-symbols-rounded text-[20px]">restaurant_menu</span> Lihat Menu
                </a>
            </template>
        </div>

        {{-- Daftar item --}}
        <div x-show="lines.length > 0" x-cloak>
            <div class="mb-3">
                <p class="text-[13px] font-semibold text-yovel-muted" x-text="totalQty + ' item di keranjang'"></p>
            </div>

            <ul class="space-y-3">
                <template x-for="line in lines" :key="line.lineId">
                    <li x-show="!line.removing"
                        x-transition:leave="transition ease-in duration-200"
                        x-transition:leave-start="opacity-100 scale-100"
                        x-transition:leave-end="opacity-0 scale-95"
                        class="card-surface p-4 flex gap-4">

                        <div class="w-20 h-20 rounded-2xl bg-yovel-surface overflow-hidden shrink-0 flex items-center justify-center">
                            <template x-if="line.photo">
                                <img :src="line.photo" :alt="line.name" loading="lazy" class="w-full h-full object-cover">
                            </template>
                            <template x-if="!line.photo">
                                <span class="material-symbols-rounded text-[32px] text-primary-300">coffee</span>
                            </template>
                        </div>

                        <div class="flex-1 min-w-0 flex flex-col">
                            <h2 class="text-[15px] font-bold leading-snug" x-text="line.name"></h2>

                            <div class="flex flex-wrap gap-1.5 mt-2" x-show="line.options.length > 0">
                                <template x-for="(opt, i) in line.options" :key="i">
                                    <span class="inline-flex items-center rounded-lg bg-yovel-surface border border-yovel-border px-2 py-0.5 text-[12px] font-medium text-yovel-muted">
                                        <span x-text="opt.name"></span>
                                        <span x-show="opt.extra > 0" class="ml-1 opacity-70" x-text="'+' + rupiah(opt.extra)"></span>
                                    </span>
                                </template>
                            </div>

                            <p x-show="line.notes" class="mt-2 text-[12px] italic text-yovel-muted" x-text="'Catatan: ' + line.notes"></p>

                            <div class="mt-auto pt-3 flex items-center justify-between gap-3">
                                <span class="text-[15px] font-extrabold tabular-nums" x-text="rupiah(line.unitPrice * line.qty)"></span>

                                <div class="flex items-center rounded-full bg-yovel-surface border border-yovel-border p-0.5" role="group" :aria-label="'Jumlah ' + line.name">
                                    <button type="button" @click="change(line, -1)" :disabled="busy"
                                            :aria-label="line.qty === 1 ? 'Hapus ' + line.name : 'Kurangi jumlah'"
                                            class="w-11 h-11 rounded-full flex items-center justify-center bg-white shadow-sm text-yovel-ink hover:bg-yovel-bg active:scale-90 disabled:opacity-50 transition-all duration-200">
                                        <span class="material-symbols-rounded text-[20px]" x-text="line.qty === 1 ? 'delete' : 'remove'"></span>
                                    </button>
                                    <span class="w-9 text-center font-bold text-[15px] tabular-nums" x-text="line.qty" aria-live="polite"></span>
                                    <button type="button" @click="change(line, 1)" :disabled="busy || line.qty >= 20"
                                            aria-label="Tambah jumlah"
                                            class="w-11 h-11 rounded-full flex items-center justify-center bg-white shadow-sm text-yovel-ink hover:bg-yovel-bg active:scale-90 disabled:opacity-40 transition-all duration-200">
                                        <span class="material-symbols-rounded text-[20px]">add</span>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </li>
                </template>
            </ul>

            <template x-if="menuUrl">
                <a :href="menuUrl" class="mt-4 inline-flex items-center gap-1.5 text-[14px] font-semibold text-yovel-muted hover:text-yovel-ink active:scale-95 transition-all duration-200 min-h-11">
                    <span class="material-symbols-rounded text-[18px]">add_circle</span> Tambah menu lain
                </a>
            </template>

            <div class="h-64" aria-hidden="true"></div>
        </div>

        {{-- Bar ringkasan + CTA (fixed) --}}
        <div x-show="lines.length > 0" x-cloak
             class="fixed inset-x-0 bottom-0 z-30 mx-auto max-w-md bg-white/90 backdrop-blur-xl border-t border-yovel-border sm:border-x">
            <div class="px-4 pt-4 pb-safe">
                <dl class="text-[14px] space-y-1.5 mb-4" aria-live="polite">
                    <div class="flex justify-between text-yovel-muted"><dt>Subtotal</dt><dd class="tabular-nums" x-text="rupiah(subtotal)"></dd></div>
                    <div class="flex justify-between text-yovel-muted"><dt x-text="'Pajak (' + taxPercent + '%)'"></dt><dd class="tabular-nums" x-text="rupiah(tax)"></dd></div>
                </dl>
                <a :href="checkoutUrl" :class="busy ? 'pointer-events-none opacity-60' : ''"
                   class="flex items-center justify-between w-full rounded-2xl bg-yovel-ink text-white px-5 min-h-14 font-semibold shadow-lg hover:bg-black active:scale-[0.98] transition-all duration-200 focus-visible:ring-2 focus-visible:ring-yovel-ink focus-visible:ring-offset-2">
                    <span class="text-[15px]">Lanjut ke Pembayaran</span>
                    <span class="tabular-nums text-[15px]" x-text="rupiah(total)"></span>
                </a>
                <p class="text-[11px] text-yovel-muted text-center mt-2">Total sudah termasuk pajak. Nominal final tampil di layar pembayaran.</p>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.data('cartPage', (cfg) => ({
                lines: cfg.lines.map(l => ({ ...l, removing: false })),
                busy: false,
                menuUrl: cfg.menuUrl,
                checkoutUrl: cfg.checkoutUrl,
                taxPercent: Math.round(cfg.taxRate * 1000) / 10,

                get active() { return this.lines.filter(l => !l.removing); },
                get subtotal() { return this.active.reduce((s, l) => s + l.unitPrice * l.qty, 0); },
                get totalQty() { return this.active.reduce((s, l) => s + l.qty, 0); },
                get tax() { return Math.round(this.subtotal * cfg.taxRate); },
                get total() {
                    const raw = this.subtotal + this.tax, v = cfg.roundingValue;
                    switch (cfg.roundingBehavior) {
                        case 'ROUND_NEAREST': return Math.round(raw / v) * v;
                        case 'ROUND_UP': return Math.ceil(raw / v) * v;
                        case 'ROUND_DOWN': return Math.floor(raw / v) * v;
                        default: return Math.round(raw);
                    }
                },

                rupiah(n) { return 'Rp ' + new Intl.NumberFormat('id-ID').format(Math.round(n)); },
                notify(message) { this.$dispatch('toast', { message }); },

                async send(method, line, body) {
                    const res = await fetch(cfg.urlTemplate.replace('__LINE__', encodeURIComponent(line.lineId)), {
                        method,
                        headers: {
                            'Accept': 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                        },
                        body: body ? JSON.stringify(body) : undefined,
                    });
                    if (!res.ok) throw new Error('HTTP ' + res.status);
                    return res.json();
                },

                async change(line, delta) {
                    if (this.busy) return;
                    const next = line.qty + delta;
                    if (next < 1) return this.remove(line);
                    if (next > 20) return;
                    const prev = line.qty;
                    line.qty = next; // optimistic update
                    this.busy = true;
                    try {
                        await this.send('PATCH', line, { qty: next });
                    } catch (e) {
                        line.qty = prev;
                        this.notify('Gagal memperbarui jumlah. Periksa koneksi Anda.');
                    } finally {
                        this.busy = false;
                    }
                },

                async remove(line) {
                    if (this.busy) return;
                    this.busy = true;
                    line.removing = true;
                    try {
                        await this.send('DELETE', line);
                        setTimeout(() => { this.lines = this.lines.filter(l => l.lineId !== line.lineId); }, 220);
                    } catch (e) {
                        line.removing = false;
                        this.notify('Gagal menghapus item. Coba lagi.');
                    } finally {
                        this.busy = false;
                    }
                },
            }));
        });
    </script>
</x-customer-layout>
