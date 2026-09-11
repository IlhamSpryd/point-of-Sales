<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('Laporan Penjualan') }}
        </h2>
    </x-slot>
<div class="px-4 sm:px-6 lg:px-8 py-8 w-full max-w-9xl mx-auto">

    <!-- Page header -->
    <div class="sm:flex sm:justify-between sm:items-center mb-8">
        <!-- Left: Title -->
        <div class="mb-4 sm:mb-0">
            <h1 class="text-2xl md:text-3xl text-gray-800 dark:text-gray-100 font-bold">{{ $title }}</h1>
        </div>
    </div>

    <!-- Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6 mb-8">
        
        <!-- Harian -->
        <div class="flex flex-col col-span-1 bg-white dark:bg-gray-800 shadow-sm rounded-xl">
            <div class="px-5 pt-5">
                <header class="flex justify-between items-start mb-2">
                    <h2 class="text-lg font-semibold text-gray-800 dark:text-gray-100 mb-2">Pendapatan Hari Ini</h2>
                </header>
                <div class="text-3xl font-bold text-gray-800 dark:text-gray-100 mr-2">Rp {{ number_format($dailySales->total, 0, ',', '.') }}</div>
                <div class="text-sm font-semibold text-gray-500 dark:text-gray-400 mt-2">{{ $dailySales->count }} Transaksi</div>
            </div>
            <div class="grow"></div>
        </div>
        
        <!-- Mingguan -->
        <div class="flex flex-col col-span-1 bg-white dark:bg-gray-800 shadow-sm rounded-xl">
            <div class="px-5 pt-5">
                <header class="flex justify-between items-start mb-2">
                    <h2 class="text-lg font-semibold text-gray-800 dark:text-gray-100 mb-2">Pendapatan Minggu Ini</h2>
                </header>
                <div class="text-3xl font-bold text-gray-800 dark:text-gray-100 mr-2">Rp {{ number_format($weeklySales->total, 0, ',', '.') }}</div>
                <div class="text-sm font-semibold text-gray-500 dark:text-gray-400 mt-2">{{ $weeklySales->count }} Transaksi</div>
            </div>
            <div class="grow"></div>
        </div>
        
        <!-- Bulanan -->
        <div class="flex flex-col col-span-1 bg-white dark:bg-gray-800 shadow-sm rounded-xl">
            <div class="px-5 pt-5">
                <header class="flex justify-between items-start mb-2">
                    <h2 class="text-lg font-semibold text-gray-800 dark:text-gray-100 mb-2">Pendapatan Bulan Ini</h2>
                </header>
                <div class="text-3xl font-bold text-gray-800 dark:text-gray-100 mr-2">Rp {{ number_format($monthlySales->total, 0, ',', '.') }}</div>
                <div class="text-sm font-semibold text-gray-500 dark:text-gray-400 mt-2">{{ $monthlySales->count }} Transaksi</div>
            </div>
            <div class="grow"></div>
        </div>

    </div>

    <!-- Table -->
    <div class="bg-white dark:bg-gray-800 shadow-sm rounded-xl mb-8">
        <header class="px-5 py-4 border-b border-gray-100 dark:border-gray-700/60">
            <h2 class="font-semibold text-gray-800 dark:text-gray-100">Daftar Transaksi Terbaru</h2>
        </header>
        <div class="p-3">
            <div class="overflow-x-auto">
                <table class="table-auto w-full dark:text-gray-300">
                    <thead class="text-xs font-semibold uppercase text-gray-400 dark:text-gray-500 bg-gray-50 dark:bg-gray-700/50">
                        <tr>
                            <th class="p-2 whitespace-nowrap"><div class="font-semibold text-left">Kode Pesanan</div></th>
                            <th class="p-2 whitespace-nowrap"><div class="font-semibold text-left">Tanggal</div></th>
                            <th class="p-2 whitespace-nowrap"><div class="font-semibold text-left">Kasir</div></th>
                            <th class="p-2 whitespace-nowrap"><div class="font-semibold text-right">Nilai Transaksi</div></th>
                        </tr>
                    </thead>
                    <tbody class="text-sm divide-y divide-gray-100 dark:divide-gray-700/60">
                        @forelse ($recentOrders as $order)
                        <tr>
                            <td class="p-2 whitespace-nowrap">
                                <div class="font-medium text-gray-800 dark:text-gray-100">{{ $order->order_code }}</div>
                            </td>
                            <td class="p-2 whitespace-nowrap">
                                <div class="text-left font-medium">{{ \Carbon\Carbon::parse($order->created_at)->format('d-m-Y H:i') }}</div>
                            </td>
                            <td class="p-2 whitespace-nowrap">
                                <div class="text-left font-medium">{{ $order->user->name ?? 'Kasir' }}</div>
                            </td>
                            <td class="p-2 whitespace-nowrap">
                                <div class="font-bold text-gray-800 dark:text-gray-100 text-right">Rp {{ number_format($order->order_amount, 0, ',', '.') }}</div>
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="4" class="p-2 whitespace-nowrap text-center text-gray-500">Belum ada transaksi di periode ini.</td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        <div class="px-5 py-4">
            {{ $recentOrders->links() }}
        </div>
    </div>

</div>
</x-app-layout>
