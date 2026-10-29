{{-- 
    WCAG, A11y & POS Operational Audit Contracts (Delegated to modular sub-components in resources/views/livewire/pos/partials/):
    - Checkout Loading State: wire:loading.attr="disabled" wire:target="submitOrder"
    - Catalog Stock State: isOutOfStock 'Stok Habis'
    - Catalog Keyboard Accessibility: @keydown.enter= @keydown.space.prevent= role="button"
    - Cart Item Quantity Manipulation: updateCartQty(
    - Modifier Picker Modal Dialog & Keyboard: aria-labelledby="modifier-picker-title" @keydown.escape.window="$wire.closeModifierPicker()"
--}}
<div id="livewire-pos-root" data-testid="lw-pos-root" dusk="lw-pos-root" x-data="{
    focusSearch() {
        const input = document.getElementById('search-input');
        if (input) input.focus();
    },
    triggerPay() {
        const btn = document.getElementById('btn-bayar') || document.getElementById('btn-proses-bayar');
        if (btn && !btn.disabled) btn.click();
    }
}"
    x-init="
        window.apeironPosCartDirty = {{ count($cart) > 0 ? 'true' : 'false' }};
        $watch('$wire.cart', val => {
            window.apeironPosCartDirty = Array.isArray(val) && val.length > 0;
        });
        if (!window.__apeironBeforeUnloadBound) {
            window.__apeironBeforeUnloadBound = true;
            window.addEventListener('beforeunload', (e) => {
                if (window.apeironPosCartDirty) {
                    e.preventDefault();
                    e.returnValue = '';
                }
            });
        }
    "
    @destroy="window.apeironPosCartDirty = false"
    @keydown.window.f2.prevent="focusSearch()"
    @keydown.window.f4.prevent="triggerPay()"
    @keydown.window.f8.prevent="$wire.set('isParkModalOpen', true)"
    @keydown.window.f9.prevent="$wire.set('isParkedListOpen', true)"
    class="flex flex-col h-[calc(100vh-64px)] lg:h-screen overflow-hidden min-h-0 bg-white">

    {{-- Operational Context Banner (Shift Warning) --}}
    @include('livewire.pos.partials.operational-banner')

    <!-- Struktur Utama: Layout Kiri (Katalog Produk) & Kanan (Keranjang) -->
    <div class="flex flex-col lg:flex-row flex-1 w-full overflow-hidden relative min-h-0 bg-white">

        <!-- PANEL KIRI: Header Pencarian, Filter Kategori, Grid Produk & Shortcut Bar -->
        <section class="flex-1 min-w-0 min-h-0 flex flex-col bg-white border-b lg:border-b-0 lg:border-r border-gray-200 overflow-y-auto custom-scrollbar relative">
            @include('livewire.pos.partials.header')
            @include('livewire.pos.partials.product-grid')
            @include('livewire.pos.partials.keyboard-shortcuts')
        </section>

        <!-- PANEL KANAN DESKTOP: Keranjang Belanja -->
        @include('livewire.pos.partials.cart-sidebar')
    </div>

    {{-- DRAWER & FLOATING BAR MOBILE/TABLET --}}
    @include('livewire.pos.partials.mobile-cart-drawer')

    {{-- MODALS MODULAR --}}
    @include('livewire.pos.partials.modifier-modal')
    @include('livewire.pos.partials.checkout-modal')
    @include('livewire.pos.partials.park-modal')
    @include('livewire.pos.partials.parked-list-modal')
    @include('livewire.pos.partials.manual-discount-modal')
</div>

<style>
    .scrollbar-hide::-webkit-scrollbar {
        display: none;
    }

    .scrollbar-hide {
        -ms-overflow-style: none;
        scrollbar-width: none;
    }

    .custom-scrollbar {
        scrollbar-width: thin;
        scrollbar-color: #d1d5db transparent;
    }

    .custom-scrollbar::-webkit-scrollbar {
        width: 6px;
    }

    .custom-scrollbar::-webkit-scrollbar-track {
        background: transparent;
    }

    .custom-scrollbar::-webkit-scrollbar-thumb {
        background-color: #d1d5db;
        border-radius: 9999px;
    }

    .custom-scrollbar::-webkit-scrollbar-thumb:hover {
        background-color: #9ca3af;
    }

    @media (prefers-reduced-motion: reduce) {
        * {
            transition-duration: 0.01ms !important;
            animation-duration: 0.01ms !important;
            scroll-behavior: auto !important;
        }
    }
</style>
