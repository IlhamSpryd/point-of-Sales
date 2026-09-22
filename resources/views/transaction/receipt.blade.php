<x-app-layout>
    {{-- Toolbar ini HANYA muncul di layar, hilang total saat print --}}
    <div class="print:hidden max-w-md mx-auto mb-4 flex justify-between items-center">
        <h1 class="text-xl font-bold">Konfirmasi Pesanan</h1>
        <div class="flex gap-2">
            <a href="{{ route('transaction.create') }}"
               class="px-4 py-2 rounded border" wire:navigate>Pesanan Baru</a>
            <button onclick="window.print()"
                    class="px-4 py-2 rounded bg-blue-600 text-white font-bold">
                🖨 Cetak Struk
            </button>
        </div>
    </div>

    <div id="receipt" class="receipt-area mx-auto bg-white text-black">
        <div class="text-center mb-2">
            <p class="font-bold text-base">YOVEL COFFEE & CAFE</p>
            <p class="text-[10px]">Jl. Contoh No. 123, Jakarta</p>
            <p class="text-[10px]">{{ $order->created_at->format('d/m/Y H:i') }}</p>
        </div>

        <div class="border-t border-b border-dashed border-black py-1 text-[11px]">
            <div class="flex justify-between"><span>No. Order</span><span>{{ $order->order_code }}</span></div>
            <div class="flex justify-between"><span>Kasir</span><span>{{ $order->user->name ?? '-' }}</span></div>
            <div class="flex justify-between">
                <span>Tipe</span>
                <span>{{ $order->order_type->value === 'dine_in' ? 'Dine-In' : 'Takeaway' }}</span>
            </div>
            @if ($order->table)
                <div class="flex justify-between">
                    <span>Meja</span><span>{{ $order->table->table_name }}</span>
                </div>
            @endif
        </div>

        <div class="py-1">
            @foreach ($order->orderItems as $item)
                <div class="text-[11px] mb-1">
                    <div class="flex justify-between">
                        <span>{{ $item->qty }}x {{ $item->product->product_name }}</span>
                        <span>{{ number_format($item->order_subtotal, 0, ',', '.') }}</span>
                    </div>

                    {{-- Modifier di-indent di bawah nama produk utama --}}
                    @foreach ($item->options ?? [] as $mod)
                        <div class="pl-4 text-[9px] text-gray-700">
                            + {{ $mod['name'] ?? $mod['modifier_name'] ?? '-' }}
                            @if (($mod['extra_price'] ?? 0) > 0)
                                (+{{ number_format($mod['extra_price'], 0, ',', '.') }})
                            @endif
                        </div>
                    @endforeach

                    @if ($item->notes)
                        <div class="pl-4 text-[9px] italic text-gray-700">
                            Catatan: {{ $item->notes }}
                        </div>
                    @endif
                </div>
            @endforeach
        </div>

        <div class="border-t border-dashed border-black pt-1 text-[11px]">
            <div class="flex justify-between">
                <span>Subtotal</span><span>{{ number_format($order->subtotal_amount, 0, ',', '.') }}</span>
            </div>
            <div class="flex justify-between">
                <span>Pajak ({{ rtrim(rtrim(number_format($taxRatePercent ?? 11, 1), '0'), '.') }}%)</span><span>{{ number_format($order->tax_amount, 0, ',', '.') }}</span>
            </div>
            <div class="flex justify-between font-bold text-[13px] mt-1">
                <span>TOTAL</span><span>{{ number_format($order->order_amount, 0, ',', '.') }}</span>
            </div>

            @if ($order->payments && $order->payments->count() > 0)
                <div class="mt-1">
                    @foreach ($order->payments as $payment)
                        <div class="flex justify-between">
                            <span>{{ ucfirst($payment->payment_method?->value ?? 'Pembayaran') }}</span><span>{{ number_format($payment->amount, 0, ',', '.') }}</span>
                        </div>
                    @endforeach
                </div>
            @elseif ($order->payment_method?->value === 'cash')
                <div class="flex justify-between mt-1">
                    <span>Tunai</span><span>{{ number_format($order->cash_received, 0, ',', '.') }}</span>
                </div>
                <div class="flex justify-between">
                    <span>Kembali</span><span>{{ number_format($order->order_change, 0, ',', '.') }}</span>
                </div>
            @endif
        </div>

        <div class="text-center text-[10px] mt-2 border-t border-dashed border-black pt-1">
            <p>Terima kasih!</p>
            <p>Selamat menikmati ☕</p>
        </div>
    </div>

    <style>
        :root {
            --receipt-width: {{ config('pos.receipt_width_mm', 58) }}mm;
        }

        .receipt-area {
            width: var(--receipt-width);
            font-family: 'Courier New', Courier, monospace;
        }

        @media print {
            /* Ini mengatur UKURAN KERTAS FISIK yang diminta ke printer --
               tanpa baris ini, hanya hasil hide sidebar, kertas tetap
               dianggap A4 oleh browser/driver. */
            @page {
                size: {{ (int) config('pos.receipt_width_mm', 58) }}mm auto;
                margin: 0;
            }

            /* Safety net: sembunyikan SEGALA sesuatu di halaman (termasuk
               layout wrapper/navbar dari x-app-layout yang tidak bisa kita
               tandai print:hidden langsung dari file ini), lalu munculkan
               HANYA area struk. */
            html, body { height: auto !important; overflow: visible !important; }
            body * { visibility: hidden; }
            #receipt, #receipt * { visibility: visible; }

            #receipt {
                position: absolute;
                top: 0;
                left: 0;
                padding: 2mm;
            }
        }
    </style>
</x-app-layout>
