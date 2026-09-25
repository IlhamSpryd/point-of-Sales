<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-bold leading-tight text-yovel-ink">
            {{ __('Riwayat Pesanan') }}
        </h2>
    </x-slot>

    <livewire:kasir.order-history />
</x-app-layout>
