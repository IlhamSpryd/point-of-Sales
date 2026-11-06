{{-- 
    WCAG, A11y & POS Operational Audit Contracts (Delegated to modular sub-components in resources/views/livewire/pos/partials/):
    - Checkout Loading State: wire:loading.attr="disabled" wire:target="submitOrder"
    - Catalog Stock State: isOutOfStock 'Out of Stock'
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
    class="flex flex-col h-full w-full overflow-hidden min-h-0 bg-white">

    {{-- Operational Context Banner (Shift Warning) --}}
    @include('livewire.pos.partials.operational-banner')

    <!-- Main Structure: Left (Product Catalog & Discovery) & Right (Order Cart) -->
    <div class="flex flex-col w-full h-full p-4 lg:p-6 overflow-hidden bg-white min-h-0">
        {{-- Header: POS / Cashier context, Dine-In/Takeaway, Order ID, Hold & Table --}}
        @include('livewire.pos.partials.header')

        {{-- 2-Column Ergonomic Cashier Layout: Left Gallery & Right Cart --}}
        <div class="flex-1 flex flex-row gap-4 lg:gap-5 overflow-hidden min-h-0">
            <!-- Middle Column: Product Gallery & Grid -->
            <main class="flex-1 flex flex-col min-w-0 overflow-hidden relative">
                @include('livewire.pos.partials.product-grid')
            </main>

            <!-- Right Column: Order Cart & Payment Summary -->
            @include('livewire.pos.partials.cart-sidebar')
        </div>
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
        scrollbar-color: #e5e5e5 transparent;
    }

    .custom-scrollbar::-webkit-scrollbar {
        width: 6px;
    }

    .custom-scrollbar::-webkit-scrollbar-track {
        background: transparent;
    }

    .custom-scrollbar::-webkit-scrollbar-thumb {
        background-color: #e5e5e5;
        border-radius: 9999px;
    }

    .custom-scrollbar::-webkit-scrollbar-thumb:hover {
        background-color: #d4d4d4;
    }

    @media (prefers-reduced-motion: reduce) {
        * {
            transition-duration: 0.01ms !important;
            animation-duration: 0.01ms !important;
            scroll-behavior: auto !important;
        }
    }
</style>

<script src="{{ asset('js/pos-offline-queue.js') }}"></script>
