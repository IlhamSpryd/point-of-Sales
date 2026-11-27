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
    },
    // R-009: kursor baris keranjang untuk navigasi keyboard.
    cartCursor: -1,
    moveCartCursor(dir) {
        if (this.$wire.pendingOrderId !== null && this.$wire.pendingOrderId !== undefined) return;
        const count = (this.$wire.cart || []).length;
        if (!count) { this.cartCursor = -1; window.__posCartCursor = -1; return; }
        this.cartCursor = Math.min(count - 1, Math.max(0, this.cartCursor + dir));
        window.__posCartCursor = this.cartCursor;
        document.querySelectorAll('[data-cart-index]').forEach((el) => {
            const idx = parseInt(el.getAttribute('data-cart-index'), 10);
            el.classList.toggle('ring-2', idx === this.cartCursor);
            el.classList.toggle('ring-foreground', idx === this.cartCursor);
        });
        const row = document.querySelector(`[data-cart-index='${this.cartCursor}']`);
        if (row) row.scrollIntoView({ block: 'nearest' });
    },
    bumpCartCursor(dir) {
        if (this.cartCursor < 0) { this.moveCartCursor(1); return; }
        this.$wire.updateCartQty(this.cartCursor, dir);
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

        // R-006: Hardware Barcode Scanner Global Detection (< 65ms per keystroke).
        // Antrean (queue) berbasis window agar scan beruntun tidak hilang saat
        // Livewire melakukan DOM morph — kiriman berikutnya menunggu promise
        // sebelumnya selesai, bukan dibuang.
        window.__barcodeQueue = [];
        window.__barcodeBusy = false;
        window.__barcodeFlush = async () => {
            if (window.__barcodeBusy) return;
            window.__barcodeBusy = true;
            try {
                while (window.__barcodeQueue.length > 0) {
                    const code = window.__barcodeQueue.shift();
                    try {
                        await $wire.scanBarcode(code);
                    } catch (err) {
                        // Scan lanjutan tetap diproses walau satu gagal.
                    }
                }
            } finally {
                window.__barcodeBusy = false;
            }
        };
        let barcodeBuf = '';
        let lastCharTime = Date.now();
        let debounceTimer = null;
        const barcodeKeyHandler = (e) => {
            const el = e.target;
            const isEditing = el && (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA' || el.isContentEditable);
            const now = Date.now();
            if (now - lastCharTime > 65) {
                barcodeBuf = '';
            }
            lastCharTime = now;
            if (e.key === 'Enter' && barcodeBuf.length >= 3 && !isEditing) {
                e.preventDefault();
                const code = barcodeBuf;
                barcodeBuf = '';
                // Debounce 80ms: scanner kadang mengirim Enter dobel.
                clearTimeout(debounceTimer);
                debounceTimer = setTimeout(() => {
                    window.__barcodeQueue.push(code);
                    window.__barcodeFlush();
                }, 80);
                return;
            }
            if (e.key.length === 1 && !e.ctrlKey && !e.altKey && !e.metaKey && !isEditing) {
                barcodeBuf += e.key;
            }
        };
        window.addEventListener('keydown', barcodeKeyHandler);
        if (typeof $cleanup === 'function') {
            $cleanup(() => window.removeEventListener('keydown', barcodeKeyHandler));
        }
        document.addEventListener('livewire:navigating', () => {
            window.removeEventListener('keydown', barcodeKeyHandler);
        }, { once: true });
    
    "
    @destroy="window.apeironPosCartDirty = false"
    {{-- R-009 / WCAG AAA: navigasi keranjang penuh via keyboard — panah atas/bawah memilih baris, +/- mengubah kuantitas. --}}
    @keydown.window.arrowdown.prevent="moveCartCursor(1)"
    @keydown.window.arrowup.prevent="moveCartCursor(-1)"
    @keydown.window="
        if (['INPUT', 'TEXTAREA', 'SELECT'].includes($event.target?.tagName) || $event.target?.isContentEditable) return;
        if ($event.key === '+' || $event.key === '=') { $event.preventDefault(); bumpCartCursor(1); }
        else if ($event.key === '-') { $event.preventDefault(); bumpCartCursor(-1); }
    "
    @keydown.window.f2.prevent="focusSearch()"
    @keydown.window.f4.prevent="triggerPay()"
    @keydown.window.f5.prevent="$wire.fastCashPay(0)"
    @keydown.window.f8.prevent="$wire.set('isParkModalOpen', true)"
    @keydown.window.f9.prevent="$wire.set('isParkedListOpen', true)"
    @offline-orders-synced.window="
        window.apeironPosCartDirty = false;
        if (window.__apeironQueuedOfflineDraft && ($wire.cart || []).length > 0) {
            window.__apeironQueuedOfflineDraft = false;
            $wire.clearCartAfterOfflineQueue();
        }
    "
    class="flex flex-col h-full w-full overflow-hidden min-h-0 bg-background">

    {{-- Operational Context Banner (Shift Warning) --}}
    @include('livewire.pos.partials.operational-banner')

    <!-- Main Structure: Left (Header + Product Catalog & Discovery) & Right (Full-Height Order Cart) -->
    <div class="flex flex-row w-full h-full p-3 lg:p-4 gap-3 lg:gap-4 overflow-hidden bg-background min-h-0">
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
        scrollbar-color: var(--border-strong) transparent;
    }

    .custom-scrollbar::-webkit-scrollbar {
        width: 6px;
    }

    .custom-scrollbar::-webkit-scrollbar-track {
        background: transparent;
    }

    .custom-scrollbar::-webkit-scrollbar-thumb {
        background-color: var(--border-strong);
        border-radius: 9999px;
    }

    .custom-scrollbar::-webkit-scrollbar-thumb:hover {
        background-color: var(--muted-foreground);
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
@assets
@if ($midtransClientKey)
    <script src="{{ $midtransSnapSrc }}" data-client-key="{{ $midtransClientKey }}"></script>
@endif
<script>
    window.ApeironTenantId = @js(auth()->user()?->tenant_id);
    window.ApeironUserId = @js(auth()->id());
    window.ApeironShiftId = @js($this->activeShift?->id);
</script>
<script src="{{ asset('js/pos-offline-queue.js') }}"></script>
@endassets
