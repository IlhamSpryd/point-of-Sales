{{-- 
    WCAG, A11y & POS Operational Audit Contracts (Delegated to modular sub-components in resources/views/livewire/pos/partials/):
    - Checkout Loading State: wire:loading.attr="disabled" wire:target="submitOrder"
    - Catalog Stock State: isOutOfStock 'Out of Stock' 'Stok Habis'
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

        // Hardware Barcode Scanner Global Detection (< 65ms per keystroke)
        let barcodeBuf = '';
        let lastCharTime = Date.now();
        window.addEventListener('keydown', (e) => {
            const el = e.target;
            const isEditing = el && (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA' || el.isContentEditable);
            const now = Date.now();
            if (now - lastCharTime > 65) {
                barcodeBuf = '';
            }
            lastCharTime = now;
            if (e.key === 'Enter' && barcodeBuf.length >= 3 && !isEditing) {
                e.preventDefault();
                $wire.scanBarcode(barcodeBuf);
                barcodeBuf = '';
                return;
            }
            if (e.key.length === 1 && !e.ctrlKey && !e.altKey && !e.metaKey && !isEditing) {
                barcodeBuf += e.key;
            }
        });
    "
    @destroy="window.apeironPosCartDirty = false"
    @keydown.window.f2.prevent="focusSearch()"
    @keydown.window.f4.prevent="triggerPay()"
    @keydown.window.f5.prevent="$wire.fastCashPay(0)"
    @keydown.window.f8.prevent="$wire.set('isParkModalOpen', true)"
    @keydown.window.f9.prevent="$wire.set('isParkedListOpen', true)"
    class="flex flex-col h-full w-full overflow-hidden min-h-0 bg-white">

    {{-- Operational Context Banner (Shift Warning) --}}
    @include('livewire.pos.partials.operational-banner')

    <!-- Main Structure: Left (Header + Product Catalog & Discovery) & Right (Full-Height Order Cart) -->
    <div class="flex flex-row w-full h-full p-3 lg:p-4 gap-3 lg:gap-4 overflow-hidden bg-white min-h-0">
        <!-- Left Column: POS Workspace (Header + Catalog & Grid) -->
        <div class="flex-1 flex flex-col min-w-0 overflow-hidden relative">
            {{-- Header: POS / Cashier context, Dine-In/Takeaway, Order ID, Hold & Table --}}
            @include('livewire.pos.partials.header')

            <!-- Middle Column: Product Gallery & Grid -->
            <main class="flex-1 flex flex-col min-w-0 overflow-hidden relative">
                @include('livewire.pos.partials.product-grid')
            </main>
        </div>

        <!-- Right Column: Full-Height Order Cart & Payment Summary -->
        @include('livewire.pos.partials.cart-sidebar')
    </div>

    {{-- DRAWER & FLOATING BAR MOBILE/TABLET --}}
    @include('livewire.pos.partials.mobile-cart-drawer')

    {{-- MODALS MODULAR --}}
    @include('livewire.pos.partials.modifier-modal')
    @include('livewire.pos.partials.variant-modal')
    @include('livewire.pos.partials.split-bill-modal')
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

@php
    $midtransClientKey = config('services.midtrans.client_key');
    $midtransSnapSrc = config('services.midtrans.is_production')
        ? 'https://app.midtrans.com/snap/snap.js'
        : 'https://app.sandbox.midtrans.com/snap/snap.js';
@endphp
@if ($midtransClientKey)
    <script src="{{ $midtransSnapSrc }}" data-client-key="{{ $midtransClientKey }}"></script>
@endif
<script src="{{ asset('js/pos-offline-queue.js') }}"></script>
