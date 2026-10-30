<x-app-layout>
    <x-slot name="header">
        <x-erp.page-header
            title="Order & Transaction History"
            description="Monitor all cashier transactions, self-order settlements, status reconciliations, and sales audit logs.">
        </x-erp.page-header>
    </x-slot>

    <div class="w-full flex-1" data-gsap="page-enter">
        <livewire:kasir.order-history />
    </div>
</x-app-layout>
