<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-bold text-xl text-apeiron-ink leading-tight">
                {{ __('Detail Pesanan: ') . $order->order_code }}
            </h2>
            <div class="flex items-center gap-3">
                @if (in_array($order->order_status->value ?? $order->order_status, ['paid', 'completed'], true))
                    <a href="{{ route('transaction.receipt', $order->order_code) }}" target="_blank"
                       class="px-3 py-1.5 rounded-lg bg-apeiron-ink text-white text-xs font-semibold hover:bg-black transition-colors flex items-center gap-1.5">
                        <span class="material-symbols-rounded text-[16px]">print</span>
                        {{ ($order->print_count ?? 0) > 0 ? __('Cetak Ulang Struk (#') . (($order->print_count ?? 0) + 1) . ')' : __('Cetak Struk') }}
                    </a>
                @endif
                <a href="{{ route('orders.index') }}" class="text-sm text-apeiron-teal hover:underline">
                    &larr; {{ __('Kembali ke Riwayat') }}
                </a>
            </div>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
            <div class="bg-white p-6 rounded-xl shadow-sm border border-slate-100 space-y-6">
                <div class="flex justify-between items-center border-b pb-4">
                    <div>
                        <span class="text-xs text-slate-400 uppercase tracking-wider font-semibold">{{ __('Kode Order') }}</span>
                        <p class="text-lg font-bold text-slate-800 font-mono">{{ $order->order_code }}</p>
                        <p class="text-xs text-slate-500">{{ $order->created_at->format('d M Y H:i') }} WIB</p>
                    </div>
                    <div class="flex items-center gap-2">
                        <x-badge :status="$order->order_status" />
                        @if ($order->orderReturns && $order->orderReturns->isNotEmpty())
                            <span class="px-2.5 py-1 rounded-full text-xs font-bold bg-amber-100 text-amber-800 border border-amber-200">
                                {{ __('Retur Dicatat') }} ({{ $order->orderReturns->count() }}x)
                            </span>
                        @endif
                    </div>
                </div>

                <div class="space-y-2">
                    <h3 class="font-semibold text-slate-700 text-sm uppercase tracking-wider">{{ __('Item Pesanan') }}</h3>
                    <div class="divide-y divide-slate-100 border rounded-xl overflow-hidden">
                        @foreach($order->orderItems as $item)
                            @php
                                $returnedQty = $order->orderReturns->flatMap->items->where('order_item_id', $item->id)->sum('quantity_returned');
                            @endphp
                            <div class="p-3 flex justify-between items-center text-sm bg-white">
                                <div>
                                    <span class="font-medium text-slate-800">{{ $item->product->product_name ?? 'Produk' }}</span>
                                    <span class="text-slate-400"> x {{ $item->qty }}</span>
                                    @if ($returnedQty > 0)
                                        <span class="ml-2 inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-bold bg-amber-100 text-amber-800">
                                            Diretur {{ $returnedQty }} pcs
                                        </span>
                                    @endif
                                </div>
                                <span class="font-medium text-slate-700 tabular-nums">Rp {{ number_format((float) $item->order_subtotal, 0, ',', '.') }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>

                {{-- Riwayat Retur & Disposisi --}}
                @if ($order->orderReturns && $order->orderReturns->isNotEmpty())
                    <div class="space-y-3 pt-2">
                        <h3 class="font-semibold text-amber-800 text-sm uppercase tracking-wider">{{ __('Riwayat Retur & Disposisi Inventori') }}</h3>
                        <div class="space-y-3">
                            @foreach ($order->orderReturns as $ret)
                                <div class="bg-amber-50/60 border border-amber-200 rounded-xl p-4 text-xs space-y-2">
                                    <div class="flex justify-between items-center font-semibold text-amber-900 border-b border-amber-200 pb-2">
                                        <div class="flex items-center gap-2">
                                            <span class="font-mono text-sm">{{ $ret->return_number }}</span>
                                            <span class="uppercase text-[10px] px-2 py-0.5 rounded bg-amber-200 text-amber-900 font-bold">{{ $ret->return_type }}</span>
                                        </div>
                                        <span class="text-slate-500 font-normal">{{ $ret->created_at->format('d/m/Y H:i') }}</span>
                                    </div>
                                    <p class="text-slate-600"><strong class="text-slate-800">{{ __('Alasan:') }}</strong> {{ $ret->reason }}</p>
                                    <div class="space-y-1.5 pt-1">
                                        @foreach ($ret->items as $retItem)
                                            <div class="flex justify-between items-center bg-white p-2 rounded-lg border border-amber-100">
                                                <span>{{ $retItem->quantity_returned }}× {{ $retItem->orderItem->product->product_name ?? 'Item' }}</span>
                                                <div class="flex items-center gap-2">
                                                    <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase
                                                        {{ $retItem->disposition === 'restock' ? 'bg-emerald-100 text-emerald-800' : ($retItem->disposition === 'quarantine' ? 'bg-amber-100 text-amber-800' : 'bg-rose-100 text-rose-800') }}">
                                                        {{ $retItem->disposition }}
                                                    </span>
                                                    <span class="font-semibold tabular-nums">Rp {{ number_format($retItem->refund_amount, 0, ',', '.') }}</span>
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

                {{-- Riwayat Payment Refunds --}}
                @if ($order->paymentRefunds && $order->paymentRefunds->isNotEmpty())
                    <div class="space-y-3 pt-2">
                        <h3 class="font-semibold text-rose-800 text-sm uppercase tracking-wider">{{ __('Riwayat Pengembalian Dana (Payment Refunds)') }}</h3>
                        <div class="space-y-2">
                            @foreach ($order->paymentRefunds as $refund)
                                <div class="bg-rose-50/50 border border-rose-200 rounded-xl p-3 text-xs space-y-1.5">
                                    <div class="flex justify-between items-center">
                                        <span class="font-mono font-bold text-rose-900">{{ $refund->refund_number }}</span>
                                        <span class="font-bold text-rose-700 tabular-nums text-sm">Rp {{ number_format($refund->amount, 0, ',', '.') }}</span>
                                    </div>
                                    <div class="grid grid-cols-2 gap-2 text-slate-500 text-[11px]">
                                        <div>Metode: <strong class="text-slate-700 uppercase">{{ $refund->refund_method }}</strong></div>
                                        <div>Status: <strong class="text-emerald-700 uppercase">{{ $refund->financial_status }}</strong></div>
                                        @if ($refund->is_method_override)
                                            <div class="col-span-2 text-rose-600 font-medium">Pengecualian Tunai: {{ $refund->method_override_reason ?? 'Disetujui Supervisor' }}</div>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

                <div class="border-t pt-4 space-y-1.5 text-sm">
                    <div class="flex justify-between text-slate-500">
                        <span>{{ __('Subtotal') }}</span>
                        <span>Rp {{ number_format((float) $order->subtotal_amount, 0, ',', '.') }}</span>
                    </div>
                    @if ($order->discount_amount > 0)
                        <div class="flex justify-between text-rose-600">
                            <span>{{ __('Diskon') }}</span>
                            <span>- Rp {{ number_format((float) $order->discount_amount, 0, ',', '.') }}</span>
                        </div>
                    @endif
                    <div class="flex justify-between text-slate-500">
                        <span>{{ __('Pajak') }}</span>
                        <span>Rp {{ number_format((float) $order->tax_amount, 0, ',', '.') }}</span>
                    </div>
                    @if ($order->service_charge_amount > 0)
                        <div class="flex justify-between text-slate-500">
                            <span>{{ __('Service Charge') }}</span>
                            <span>Rp {{ number_format((float) $order->service_charge_amount, 0, ',', '.') }}</span>
                        </div>
                    @endif
                    <div class="flex justify-between text-base font-bold text-slate-800 pt-2 border-t">
                        <span>{{ __('Total Tagihan') }}</span>
                        <span>Rp {{ number_format((float) $order->order_amount, 0, ',', '.') }}</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>

