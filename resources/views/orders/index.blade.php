<x-app-layout>
    <x-slot name="header">
        <x-erp.page-header
            title="Riwayat Pesanan & Transaksi"
            description="Pantau seluruh transaksi kasir, pelunasan self-order, rekonsiliasi status, dan audit log penjualan.">
        </x-erp.page-header>
    </x-slot>

    <div class="py-6" data-gsap="page-enter">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <livewire:kasir.order-history />
        </div>
    </div>
</x-app-layout>
