<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-bold text-xl text-apeiron-ink leading-tight">
                {{ __('Detail Pesanan: ') . $order->order_code }}
            </h2>
            <a href="{{ route('orders.index') }}" class="text-sm text-apeiron-teal hover:underline">
                &larr; {{ __('Kembali ke Riwayat') }}
            </a>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
            <div class="bg-white p-6 rounded-xl shadow-sm border border-slate-100 space-y-4">
                <div class="flex justify-between items-center border-b pb-4">
                    <div>
                        <span class="text-xs text-slate-400 uppercase tracking-wider font-semibold">{{ __('Kode Order') }}</span>
                        <p class="text-lg font-bold text-slate-800">{{ $order->order_code }}</p>
                    </div>
                    <div>
                        <span class="text-xs text-slate-400 uppercase tracking-wider font-semibold">{{ __('Status') }}</span>
                        @php
                            $statusValue = $order->order_status instanceof \App\Enums\OrderStatus ? $order->order_status->value : (string) $order->order_status;
                            $statusLabel = $order->order_status instanceof \App\Enums\OrderStatus ? $order->order_status->label() : ucfirst($statusValue);
                            $statusClass = match($statusValue) {
                                'paid', 'completed' => 'bg-emerald-50 text-emerald-600',
                                'pending' => 'bg-amber-50 text-amber-600',
                                'void', 'failed' => 'bg-rose-50 text-rose-600',
                                default => 'bg-slate-50 text-slate-600',
                            };
                        @endphp
                        <p class="text-sm font-semibold capitalize px-3 py-1 rounded-full {{ $statusClass }}">
                            {{ $statusLabel }}
                        </p>
                    </div>
                </div>

                <div class="space-y-2">
                    <h3 class="font-semibold text-slate-700">{{ __('Item Pesanan') }}</h3>
                    <div class="divide-y divide-slate-100">
                        @foreach($order->orderItems as $item)
                            <div class="py-2 flex justify-between items-center text-sm">
                                <div>
                                    <span class="font-medium text-slate-800">{{ $item->product->product_name ?? 'Produk' }}</span>
                                    <span class="text-slate-400"> x {{ $item->qty }}</span>
                                </div>
                                <span class="font-medium text-slate-700">Rp {{ number_format((float) $item->order_subtotal, 0, ',', '.') }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>

                <div class="border-t pt-4 space-y-1 text-sm">
                    <div class="flex justify-between text-slate-500">
                        <span>{{ __('Subtotal') }}</span>
                        <span>Rp {{ number_format((float) $order->subtotal_amount, 0, ',', '.') }}</span>
                    </div>
                    <div class="flex justify-between text-slate-500">
                        <span>{{ __('Diskon') }}</span>
                        <span>- Rp {{ number_format((float) $order->discount_amount, 0, ',', '.') }}</span>
                    </div>
                    <div class="flex justify-between text-slate-500">
                        <span>{{ __('Pajak & Layanan') }}</span>
                        <span>Rp {{ number_format((float) ($order->tax_amount + $order->service_charge_amount), 0, ',', '.') }}</span>
                    </div>
                    <div class="flex justify-between text-base font-bold text-slate-800 pt-2 border-t">
                        <span>{{ __('Total Tagihan') }}</span>
                        <span>Rp {{ number_format((float) $order->order_amount, 0, ',', '.') }}</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
