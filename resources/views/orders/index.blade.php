<x-app-layout>
    <x-slot name="header">
        <h2 class="font-bold text-xl text-apeiron-ink leading-tight">
            {{ __('Riwayat Pesanan') }}
        </h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <livewire:kasir.order-history />
        </div>
    </div>
</x-app-layout>
