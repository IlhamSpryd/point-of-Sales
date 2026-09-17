<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pesanan Diterima</title>
    @vite(['resources/css/app.css'])
</head>
<body class="bg-gray-50 text-gray-800 font-sans antialiased flex flex-col min-h-screen">
    <div class="max-w-md mx-auto w-full flex-1 flex flex-col justify-center p-6 text-center">
        <div class="bg-white rounded-2xl shadow-lg p-8 mb-6">
            <div class="w-20 h-20 bg-green-100 text-green-500 rounded-full flex items-center justify-center mx-auto mb-4">
                <svg class="w-10 h-10" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"></path></svg>
            </div>
            <h1 class="text-2xl font-bold text-gray-900 mb-2">Pesanan Diterima!</h1>
            <p class="text-gray-500 mb-6">Terima kasih, pesanan Anda sedang kami siapkan.</p>
            
            <div class="bg-gray-50 p-4 rounded-xl border mb-6 text-left">
                <p class="text-sm text-gray-500 mb-1">Nomor Pesanan</p>
                <p class="font-bold text-lg text-gray-800">{{ $order->order_code }}</p>
                <div class="mt-4 pt-4 border-t border-gray-200 flex justify-between">
                    <span class="text-gray-600">Total Tagihan</span>
                    <span class="font-bold text-indigo-600">Rp {{ number_format($order->order_amount, 0, ',', '.') }}</span>
                </div>
            </div>

            <p class="text-sm text-gray-500">
                Silakan lakukan pembayaran di kasir atau tunggu pesanan diantar ke meja Anda.
            </p>
        </div>

        <a href="{{ route('self-order.menu', $order->table->secure_token) }}" class="inline-block bg-indigo-100 text-indigo-700 font-bold py-3 px-6 rounded-xl hover:bg-indigo-200 transition">
            Pesan Lagi
        </a>
    </div>
</body>
</html>
