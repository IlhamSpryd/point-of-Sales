<x-customer-layout>
    <div class="text-center py-10">
        <h1 class="text-xl font-bold">Pesanan #{{ $order->order_code }}</h1>
        <p class="text-sm text-zinc-500 mt-2">Meja {{ $order->table?->table_number ?? '-' }} — selesaikan pembayaran untuk melanjutkan.</p>
    </div>

    @if($order->snap_token)
        <script src="{{ config('services.midtrans.is_production') ? 'https://app.midtrans.com/snap/snap.js' : 'https://app.sandbox.midtrans.com/snap/snap.js' }}"
                data-client-key="{{ config('services.midtrans.client_key') }}"></script>
        <script>
            // Otomatis munculkan popup pembayaran begitu halaman dimuat.
            window.addEventListener('DOMContentLoaded', () => {
                snap.pay('{{ $order->snap_token }}', {
                    onSuccess: () => window.location.reload(),
                    onPending: () => {},
                    onError: () => alert('Pembayaran gagal, silakan coba lagi.'),
                });
            });
        </script>
    @endif
</x-customer-layout>
