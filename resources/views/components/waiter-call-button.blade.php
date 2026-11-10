{{-- Call Staff / Waiter Button — shared component for customer layout.
     Used in customer/menu/index.blade.php and layouts/customer.blade.php
     to ensure availability across Cart & Checkout pages. --}}
@if (session('current_table_name'))
    <button type="button" x-data="{ busy: false }" :disabled="busy" aria-label="Call Server"
            @click="busy = true;
              fetch(@js(route('customer.waiter.call')), { method: 'POST', headers: { 'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content } })
                .then(r => r.json()).then(d => $dispatch('toast', { message: d.message }))
                .catch(() => $dispatch('toast', { message: 'Unable to reach staff. Please flag a team member directly.' }))
                .finally(() => busy = false)"
            class="flex items-center justify-center w-11 h-11 rounded-full bg-card border border-border text-muted-foreground hover:text-foreground active:scale-90 transition-all shadow-sm disabled:opacity-50">
        <span class="material-symbols-rounded text-[20px]">room_service</span>
    </button>
@endif
