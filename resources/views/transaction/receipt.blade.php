<x-app-layout>
    {{-- This toolbar only appears on screen, hidden when printing --}}
    <div class="print:hidden max-w-md mx-auto mb-4 flex justify-between items-center">
        <h1 class="text-xl font-bold">Order Confirmation</h1>
        <div class="flex gap-2">
            <a href="{{ route('transaction.create') }}"
               class="px-4 py-2 rounded border" wire:navigate>New Order</a>
            <button onclick="window.print()"
                    class="px-4 py-2 rounded bg-blue-600 text-white font-bold">
                🖨 Print Receipt
            </button>
        </div>
    </div>

    <div id="receipt" class="receipt-area mx-auto bg-white text-foreground">
        <div class="text-center mb-2">
            <div class="flex justify-center mb-1.5">
                <x-brand-logo variant="symbol" mode="light" class="h-5 w-auto text-black" />
            </div>
            <p class="font-bold text-base">{{ $order->store?->name ?? 'Apeiron System' }}</p>
            @if ($order->store?->address)
                <p class="text-[10px]">{{ $order->store->address }}</p>
            @endif
            @if ($order->store?->phone)
                <p class="text-[10px]">Tel: {{ $order->store->phone }}</p>
            @endif
            <p class="text-[10px]">{{ $order->created_at->format('d/m/Y H:i') }}</p>
        </div>

        @if (($isReprint ?? false) || ($order->print_count ?? 0) > 1)
            <div class="border-y border-dashed border-foreground py-1 my-1 text-center font-bold text-[11px] tracking-wider">
                *** REPRINT #{{ $order->print_count }} ***
            </div>
        @endif

        <div class="border-t border-b border-dashed border-foreground py-1 text-[11px]">
            <div class="flex justify-between"><span>Order No.</span><span>{{ $order->order_code }}</span></div>
            <div class="flex justify-between"><span>Cashier</span><span>{{ $order->user->name ?? '-' }}</span></div>
            @if ($order->customer)
                <div class="flex justify-between"><span>Customer</span><span>{{ $order->customer->name }}</span></div>
            @endif
            @if ($order->device)
                <div class="flex justify-between"><span>Device</span><span>{{ $order->device->device_name }}</span></div>
            @elseif ($order->created_by_device_id)
                <div class="flex justify-between"><span>Device</span><span>Terminal #{{ $order->created_by_device_id }}</span></div>
            @endif
            @if ($order->shift_id)
                <div class="flex justify-between"><span>Shift</span><span>Shift #{{ $order->shift_id }}</span></div>
            @endif
            <div class="flex justify-between">
                <span>Type</span>
                <span>
                    @if($order->order_type?->value === 'dine_in') Dine-In
                    @elseif($order->order_type?->value === 'takeaway') Takeaway
                    @elseif($order->order_type?->value === 'delivery') Delivery
                    @elseif($order->order_type?->value === 'self_order') Self-Order
                    @else {{ ucfirst($order->order_type?->value ?? 'Takeaway') }} @endif
                </span>
            </div>
            @if ($order->table)
                <div class="flex justify-between">
                    <span>Table</span><span>{{ $order->table->table_name }}</span>
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

                    @if (($item->item_discount_amount ?? 0) > 0)
                        <div class="pl-4 text-[9px] text-gray-700 flex justify-between">
                            <span>* Item Discount</span>
                            <span>-{{ number_format($item->item_discount_amount, 0, ',', '.') }}</span>
                        </div>
                    @endif

                    {{-- Modifiers indented below parent product --}}
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
                            Note: {{ $item->notes }}
                        </div>
                    @endif
                </div>
            @endforeach
        </div>

        <div class="border-t border-dashed border-foreground pt-1 text-[11px]">
            <div class="flex justify-between">
                <span>Subtotal</span><span>{{ number_format($order->subtotal_amount, 0, ',', '.') }}</span>
            </div>
            @if (($order->discount_amount ?? 0) > 0)
                <div class="flex justify-between text-gray-800">
                    <span>Order Discount</span><span>-{{ number_format($order->discount_amount, 0, ',', '.') }}</span>
                </div>
            @endif
            @if (($order->tax_amount ?? 0) > 0)
                <div class="flex justify-between">
                    <span>Tax ({{ rtrim(rtrim(number_format($taxRatePercent ?? 11, 1), '0'), '.') }}%)</span><span>{{ number_format($order->tax_amount, 0, ',', '.') }}</span>
                </div>
            @endif
            @if (($order->service_charge_amount ?? 0) > 0)
                <div class="flex justify-between">
                    <span>Service Charge</span><span>{{ number_format($order->service_charge_amount, 0, ',', '.') }}</span>
                </div>
            @endif
            <div class="flex justify-between font-bold text-[13px] mt-1 border-t border-dashed border-foreground pt-1">
                <span>TOTAL</span><span>{{ number_format($order->order_amount, 0, ',', '.') }}</span>
            </div>

            @if ($order->payments && $order->payments->count() > 0)
                <div class="mt-1 pt-1 border-t border-dotted border-gray-400">
                    @foreach ($order->payments as $payment)
                        @php
                            $pmLabel = $payment->payment_method instanceof \App\Enums\PaymentMethod
                                ? $payment->payment_method->label()
                                : ucfirst((string) ($payment->payment_method ?? 'Payment'));
                            $refNum = $payment->reference_number;
                            $showRef = $refNum && !str_starts_with($refNum, 'payment:') && !str_starts_with($refNum, 'attempt-settle-');
                        @endphp
                        <div class="flex justify-between">
                            <span>{{ $pmLabel }}{{ $showRef ? ' (' . $refNum . ')' : '' }}</span>
                            <span>{{ number_format($payment->amount, 0, ',', '.') }}</span>
                        </div>
                    @endforeach
                </div>
            @endif

            @if ($order->cash_received > 0 || $order->payment_method?->value === 'cash')
                <div class="flex justify-between mt-1">
                    <span>Cash Received</span><span>{{ number_format($order->cash_received, 0, ',', '.') }}</span>
                </div>
                <div class="flex justify-between">
                    <span>Change Due</span><span>{{ number_format($order->order_change, 0, ',', '.') }}</span>
                </div>
            @endif
        </div>

        <div class="text-center text-[10px] mt-2 border-t border-dashed border-foreground pt-1">
            <p>Thank you!</p>
            <p>Enjoy your meal ☕</p>
        </div>
    </div>

    <style>
        :root {
            --receipt-width: {{ config('pos.receipt_width_mm', 58) }}mm;
        }

        .receipt-area {
            width: var(--receipt-width);
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-variant-numeric: tabular-nums;
        }

        @media print {
            @page {
                size: {{ (int) config('pos.receipt_width_mm', 58) }}mm auto;
                margin: 0;
            }

            html, body {
                height: auto !important;
                overflow: visible !important;
                background: #fff !important;
            }

            /* Hide sidebar, header, and non-receipt content during thermal receipt printing */
            #main-sidebar,
            #main-wrapper > header,
            #main-content > *:not(#receipt) {
                display: none !important;
            }

            #main-wrapper,
            #main-content {
                display: block !important;
                height: auto !important;
                overflow: visible !important;
                width: auto !important;
                padding: 0 !important;
                margin: 0 !important;
            }

            #receipt {
                position: static !important;
                width: var(--receipt-width) !important;
                max-width: var(--receipt-width) !important;
                margin: 0 !important;
                padding: 2mm !important;
            }

            /* Legibilitas di thermal head 203dpi: pastikan baris item tidak
               terpotong pertengahan saat kertas melewati batas cut otomatis. */
            #receipt > div,
            #receipt .flex.justify-between {
                page-break-inside: avoid;
            }
        }
    </style>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const params = new URLSearchParams(window.location.search);
            if (params.get('autoprint') === '1') {
                // Beri jeda singkat agar layout print selesai reflow sebelum dialog muncul.
                setTimeout(() => window.print(), 150);
            }
        });
    </script>
</x-app-layout>
