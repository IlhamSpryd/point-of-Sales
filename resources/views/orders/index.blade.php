<x-app-layout>
    <x-slot name="header">
        <x-erp.page-header
            title="Riwayat Pesanan & Transaksi"
            description="Pantau seluruh transaksi kasir, pelunasan self-order, rekonsiliasi status, dan audit log penjualan.">
        </x-erp.page-header>
    </x-slot>

    <div class="w-full flex-1" data-gsap="page-enter">
        <livewire:kasir.order-history />
    </div>
</x-app-layout>
