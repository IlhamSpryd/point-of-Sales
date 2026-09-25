{{-- Tombol Panggil Waiter — komponen bersama untuk layout pelanggan.
     Dipakai di customer/menu/index.blade.php DAN layouts/customer.blade.php
     agar tersedia di Cart & Checkout, bukan hanya halaman Menu.
     @see §5.2 audit navigasi --}}
@if (session('current_table_name'))
    <button type="button" x-data="{ busy: false }" :disabled="busy" aria-label="Panggil Waiter"
            @click="busy = true;
              fetch(@js(route('customer.waiter.call')), { method: 'POST', headers: { 'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content } })
                .then(r => r.json()).then(d => $dispatch('toast', { message: d.message }))
                .catch(() => $dispatch('toast', { message: 'Koneksi bermasalah. Silakan lambaikan tangan ke staf.' }))
                .finally(() => busy = false)"
            class="flex items-center justify-center w-11 h-11 rounded-full bg-white border border-yovel-border text-yovel-muted hover:text-yovel-ink active:scale-90 transition-all shadow-sm disabled:opacity-50">
        <span class="material-symbols-rounded text-[18px]">room_service</span>
    </button>
@endif
