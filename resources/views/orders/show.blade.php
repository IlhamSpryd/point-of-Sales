<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4" data-gsap="page-enter">
            <div>
                <div class="flex items-center gap-2.5">
                    <h2 class="font-bold text-xl text-apeiron-ink tracking-tight">
                        {{ __('Order Details: ') . $order->order_code }}
                    </h2>
                    @php
                        $statusVal = $order->order_status->value ?? (string) $order->order_status;
                        $statusVariant = match($statusVal) {
                            'paid', 'completed' => 'success',
                            'pending' => 'warning',
                            'void', 'cancelled' => 'destructive',
                            default => 'secondary'
                        };
                    @endphp
                    <x-ui.badge :variant="$statusVariant">
                        {{ ucfirst($statusVal) }}
                    </x-ui.badge>
                </div>
                <p class="text-xs text-gray-500 mt-1">
                    {{ $order->created_at->format('d M Y, H:i') }} WIB &bull; Cashier: {{ $order->user->name ?? 'POS Cashier' }}
                </p>
            </div>

            <div class="flex items-center gap-2">
                @if (in_array($statusVal, ['paid', 'completed'], true))
                    <a href="{{ route('transaction.receipt', $order->order_code) }}" target="_blank">
                        <x-ui.button variant="primary" class="h-9 text-xs" x-on:click="window.erpMotion?.pulseHighlight($el)">
                            <span class="material-symbols-rounded text-[16px]">print</span>
                            <span>{{ ($order->print_count ?? 0) > 0 ? __('Reprint (#') . (($order->print_count ?? 0) + 1) . ')' : __('Print Receipt') }}</span>
                        </x-ui.button>
                    </a>
                @endif
                <a href="{{ route('orders.index') }}" wire:navigate>
                    <x-ui.button variant="outline" class="h-9 text-xs">
                        <span class="material-symbols-rounded text-[16px]">arrow_back</span>
                        <span>{{ __('Back') }}</span>
                    </x-ui.button>
                </a>
            </div>
        </div>
    </x-slot>

    <div class="py-6" data-gsap="page-enter">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
            <div class="card-surface p-6 rounded-xl border border-apeiron-border space-y-6">
                <div class="flex justify-between items-center border-b border-gray-100 pb-4">
                    <div>
                        <span class="text-[11px] text-gray-400 uppercase tracking-wider font-semibold">{{ __('Order Code') }}</span>
                        <p class="text-lg font-bold text-apeiron-ink font-mono">{{ $order->order_code }}</p>
                        <p class="text-xs text-gray-500">{{ $order->created_at->format('d M Y, H:i') }} WIB</p>
                    </div>
                    <div class="flex items-center gap-2">
                        <x-ui.badge :variant="$statusVariant">
                            {{ ucfirst($statusVal) }}
                        </x-ui.badge>
                        @if ($order->orderReturns && $order->orderReturns->isNotEmpty())
                            <x-ui.badge variant="warning">
                                <span class="w-1.5 h-1.5 rounded-full bg-amber-500 mr-1.5"></span>
                                {{ __('Returns Recorded') }} ({{ $order->orderReturns->count() }}x)
                            </x-ui.badge>
                        @endif
                    </div>
                </div>

                <div class="space-y-2.5">
                    <h3 class="font-bold text-gray-700 text-xs uppercase tracking-wider">{{ __('Order Items') }}</h3>
                    <div class="divide-y divide-gray-100 border border-gray-200 rounded-xl overflow-hidden">
                        @foreach($order->orderItems as $item)
                            @php
                                $returnedQty = $order->orderReturns->flatMap->items->where('order_item_id', $item->id)->sum('quantity_returned');
                            @endphp
                            <div class="p-3.5 flex justify-between items-center text-xs bg-white hover:bg-gray-50/50 transition-colors">
                                <div>
                                    <span class="font-bold text-apeiron-ink text-sm">{{ $item->product->product_name ?? 'Product' }}</span>
                                    <span class="text-gray-400 font-semibold"> &times; {{ $item->qty }} pcs</span>
                                    @if ($returnedQty > 0)
                                        <x-ui.badge variant="warning" class="ml-2 text-[10px]">
                                            Returned ({{ $returnedQty }} pcs)
                                        </x-ui.badge>
                                    @endif
                                    @if($item->notes)
                                        <p class="text-gray-400 italic text-[11px] mt-0.5">{{ $item->notes }}</p>
                                    @endif
                                </div>
                                <span class="font-bold text-apeiron-ink tabular-nums text-sm">Rp {{ number_format((float) $item->order_subtotal, 0, ',', '.') }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>

                {{-- Return & Disposition History --}}
                @if ($order->orderReturns && $order->orderReturns->isNotEmpty())
                    <div class="space-y-3 pt-2">
                        <h3 class="font-bold text-amber-800 text-xs uppercase tracking-wider">{{ __('Return & Inventory Disposition History') }}</h3>
                        <div class="space-y-2.5">
                            @foreach ($order->orderReturns as $ret)
                                <div class="bg-amber-50/60 border border-amber-200 rounded-xl p-4 text-xs space-y-2">
                                    <div class="flex justify-between items-center font-bold text-amber-900 border-b border-amber-200 pb-2">
                                        <div class="flex items-center gap-2">
                                            <span class="font-mono text-sm">{{ $ret->return_number }}</span>
                                            <x-ui.badge variant="warning">{{ $ret->return_type }}</x-ui.badge>
                                        </div>
                                        <span class="text-gray-500 font-normal">{{ $ret->created_at->format('d/m/Y H:i') }}</span>
                                    </div>
                                    <p class="text-gray-600"><strong class="text-gray-800">{{ __('Reason:') }}</strong> {{ $ret->reason }}</p>
                                    <div class="space-y-1.5 pt-1">
                                        @foreach ($ret->items as $retItem)
                                            <div class="flex justify-between items-center bg-white p-2 rounded-lg border border-amber-100">
                                                <span class="font-medium">{{ $retItem->quantity_returned }}× {{ $retItem->orderItem->product->product_name ?? 'Item' }}</span>
                                                <div class="flex items-center gap-2">
                                                    <x-ui.badge :variant="match($retItem->disposition) {
                                                        'restock' => 'success',
                                                        'quarantine' => 'warning',
                                                        default => 'destructive'
                                                    }">
                                                        {{ $retItem->disposition }}
                                                    </x-ui.badge>
                                                    <span class="font-bold tabular-nums">Rp {{ number_format($retItem->refund_amount, 0, ',', '.') }}</span>
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

                {{-- Payment Refunds History --}}
                @if ($order->paymentRefunds && $order->paymentRefunds->isNotEmpty())
                    <div class="space-y-3 pt-2">
                        <h3 class="font-bold text-rose-800 text-xs uppercase tracking-wider">{{ __('Payment Refund History') }}</h3>
                        <div class="space-y-2">
                            @foreach ($order->paymentRefunds as $refund)
                                <div class="bg-rose-50/50 border border-rose-200 rounded-xl p-3.5 text-xs space-y-1.5">
                                    <div class="flex justify-between items-center">
                                        <span class="font-mono font-bold text-rose-900">{{ $refund->refund_number }}</span>
                                        <span class="font-bold text-rose-700 tabular-nums text-sm">Rp {{ number_format($refund->amount, 0, ',', '.') }}</span>
                                    </div>
                                    <div class="grid grid-cols-2 gap-2 text-gray-500 text-[11px]">
                                        <div>Method: <strong class="text-gray-700 uppercase">{{ $refund->refund_method }}</strong></div>
                                        <div>Status: <strong class="text-emerald-700 uppercase">{{ $refund->financial_status }}</strong></div>
                                        @if ($refund->is_method_override)
                                            <div class="col-span-2 text-rose-600 font-medium">Cash Exception: {{ $refund->method_override_reason ?? 'Supervisor Approved' }}</div>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

                <div class="border-t border-gray-100 pt-4 space-y-1.5 text-xs">
                    <div class="flex justify-between text-gray-500">
                        <span>{{ __('Subtotal') }}</span>
                        <span class="tabular-nums font-semibold">Rp {{ number_format((float) $order->subtotal_amount, 0, ',', '.') }}</span>
                    </div>
                    @if ($order->discount_amount > 0)
                        <div class="flex justify-between text-rose-600 font-semibold">
                            <span>{{ __('Discount') }}</span>
                            <span class="tabular-nums">- Rp {{ number_format((float) $order->discount_amount, 0, ',', '.') }}</span>
                        </div>
                    @endif
                    <div class="flex justify-between text-gray-500">
                        <span>{{ __('Tax') }}</span>
                        <span class="tabular-nums font-semibold">Rp {{ number_format((float) $order->tax_amount, 0, ',', '.') }}</span>
                    </div>
                    @if ($order->service_charge_amount > 0)
                        <div class="flex justify-between text-gray-500">
                            <span>{{ __('Service Charge') }}</span>
                            <span class="tabular-nums font-semibold">Rp {{ number_format((float) $order->service_charge_amount, 0, ',', '.') }}</span>
                        </div>
                    @endif
                    <div class="flex justify-between text-base font-bold text-apeiron-ink pt-2.5 border-t border-gray-100">
                        <span>{{ __('Total Amount') }}</span>
                        <span class="tabular-nums">Rp {{ number_format((float) $order->order_amount, 0, ',', '.') }}</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
