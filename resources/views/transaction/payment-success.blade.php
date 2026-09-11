<x-app-layout>

<div class="flex flex-col items-center justify-center min-h-[calc(100vh-64px)] bg-gray-50/50 dark:bg-gray-800/50 p-4">
    <div class="bg-white dark:bg-gray-900 p-8 md:p-10 rounded-3xl shadow-xl shadow-gray-200/50 max-w-md w-full text-center border border-gray-100 dark:border-gray-800">
        <!-- Success Icon -->
        <div class="w-20 h-20 bg-emerald-50 rounded-full flex items-center justify-center mx-auto mb-6 ring-8 ring-emerald-50/40">
            <svg class="w-10 h-10 text-emerald-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"></path>
            </svg>
        </div>
        
        <h2 class="text-2xl font-black text-gray-900 dark:text-gray-100 mb-2 font-sans tracking-tight">{{ __('Pembayaran Berhasil!') }}</h2>
        <p class="text-gray-500 dark:text-gray-400 text-sm mb-6 font-medium">
            {{ __('Terima kasih, transaksi Anda untuk Order') }} <span class="font-bold text-gray-700 dark:text-gray-300">#{{ $order_id ?? 'N/A' }}</span> {{ __('telah sukses dikonfirmasi oleh sistem.') }}
        </p>

        <div class="flex gap-3">
            <a href="{{ route('transaction.create') }}" class="flex-1 py-3.5 px-4 bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 text-gray-800 dark:text-gray-200 text-sm font-bold rounded-xl transition-all text-center">
                {{ __('Kembali') }}
            </a>
            <a href="{{ route('transaction.receipt', ['order_number' => $order_id]) }}" target="_blank" class="flex-1 py-3.5 px-4 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-bold rounded-xl transition-all shadow-md hover:shadow-lg text-center">
                {{ __('Cetak Struk') }}
            </a>
        </div>
    </div>
</div>

</x-app-layout>
