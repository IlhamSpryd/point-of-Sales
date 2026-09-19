<x-customer-layout>
    <h1 class="text-xl font-bold tracking-tight mb-4">Checkout</h1>

    <form action="{{ route('customer.checkout.store') }}" method="POST" class="space-y-5">
        @csrf
        <input type="hidden" name="_idempotency_key" value="{{ $idempotencyKey }}">

        <div class="p-3 rounded-xl bg-zinc-50 dark:bg-zinc-800 text-sm">
            Meja: <span class="font-bold">{{ session('current_table_name') }}</span>
        </div>

        <div>
            <label class="block text-sm font-medium mb-1.5">Metode Pembayaran</label>
            {{-- Hanya QRIS/E-Wallet — lihat catatan arsitektur di StoreCheckoutRequest --}}
            <select name="payment_method" required class="w-full px-4 py-2.5 rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900">
                <option value="qris">QRIS</option>
                <option value="ewallet">E-Wallet</option>
            </select>
        </div>

        <div class="pt-4 border-t border-zinc-100 dark:border-zinc-800 flex justify-between items-center">
            <span class="font-bold">Total: Rp {{ number_format($subtotal, 0, ',', '.') }}</span>
            <button type="submit" class="bg-zinc-900 text-white px-6 py-3 rounded-xl font-bold text-sm">
                Bayar Sekarang
            </button>
        </div>
    </form>
</x-customer-layout>
