<x-app-layout>
    <x-slot name="header">
        <h2 class="font-bold text-xl text-yovel-ink leading-tight">
            {{ __('Shift Kasir') }}
        </h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
            <livewire:shift-manager />
        </div>
    </div>
</x-app-layout>
