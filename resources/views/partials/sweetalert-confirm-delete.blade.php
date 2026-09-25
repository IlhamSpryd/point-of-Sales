<script>
    // ─── POLA 1: data-confirm-delete (form submit tradisional) ───────────────
    // Event delegation global — aman untuk wire:navigate SPA
    document.addEventListener('click', function(e) {
        const deleteBtn = e.target.closest('button[data-confirm-delete="true"]');
        if (deleteBtn) {
            e.preventDefault();
            const form = deleteBtn.closest('form');
            Swal.fire({
                title: 'Apakah Anda Yakin?',
                text: 'Data yang dihapus tidak dapat dikembalikan!',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#6B7280',
                confirmButtonText: 'Ya, Hapus!',
                cancelButtonText: 'Batal',
                reverseButtons: true,
                customClass: { popup: 'rounded-2xl' },
            }).then((result) => {
                if (result.isConfirmed && form) {
                    form.submit();
                }
            });
        }
    });

    // ─── POLA 2: data-swal-delete (Livewire wire:call) ───────────────────────
    // Dipakai oleh discount-manager, channel-mapping-manager, dan komponen lain
    // yang membutuhkan konfirmasi destruktif sebelum memanggil Livewire method.
    //
    // Atribut yang dibutuhkan pada tombol:
    //   data-swal-delete          — penanda tombol ini
    //   data-swal-title           — judul konfirmasi (opsional, ada default)
    //   data-swal-text            — teks deskripsi (opsional)
    //   data-wire-action          — string method Livewire, mis. "delete(5)"
    //
    // Contoh:
    //   <button data-swal-delete
    //           data-swal-title="Hapus Diskon?"
    //           data-swal-text="Diskon ini akan dihapus permanen."
    //           data-wire-action="delete(3)">
    //     Hapus
    //   </button>
    document.addEventListener('click', function(e) {
        const btn = e.target.closest('[data-swal-delete]');
        if (!btn) return;

        e.preventDefault();
        e.stopPropagation();

        const title  = btn.dataset.swalTitle  || 'Apakah Anda Yakin?';
        const text   = btn.dataset.swalText   || 'Data yang dihapus tidak dapat dikembalikan!';
        const action = btn.dataset.wireAction;

        if (!action) {
            console.warn('[swal-delete] Tombol tidak memiliki data-wire-action.', btn);
            return;
        }

        Swal.fire({
            title,
            text,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#BE123C',
            cancelButtonColor: '#6B7280',
            confirmButtonText: 'Ya, Hapus!',
            cancelButtonText: 'Batal',
            reverseButtons: true,
            customClass: { popup: 'rounded-2xl' },
        }).then((result) => {
            if (!result.isConfirmed) return;

            // Cari Livewire component terdekat
            const lwEl = btn.closest('[wire\\:id]');
            if (!lwEl) {
                console.warn('[swal-delete] Tidak dapat menemukan Livewire component untuk', btn);
                return;
            }

            // Parse "methodName(arg1, arg2)" → call $wire.methodName(arg1, arg2)
            const match = action.match(/^(\w+)\((.*)\)$/);
            if (!match) {
                console.warn('[swal-delete] Format data-wire-action tidak valid:', action);
                return;
            }

            const method = match[1];
            const rawArgs = match[2].trim();
            // Parse argumen: angka → Number, string diapit kutip → String
            const args = rawArgs === '' ? [] : rawArgs.split(',').map(a => {
                const trimmed = a.trim();
                if (/^-?\d+(\.\d+)?$/.test(trimmed)) return Number(trimmed);
                if (/^['"].*['"]$/.test(trimmed)) return trimmed.slice(1, -1);
                return trimmed;
            });

            const component = Livewire.find(lwEl.getAttribute('wire:id'));
            if (component && typeof component[method] === 'function') {
                component[method](...args);
            } else {
                // Fallback: $wire.call
                component.$wire.call(method, ...args);
            }
        });
    });

    // ─── POLA 3: Livewire event "swal-confirm" ────────────────────────────────
    // Komponen Livewire dapat mendispatch event ini untuk menampilkan konfirmasi
    // sebelum melanjutkan aksi. Berguna untuk aksi yang dipicu server-side.
    //
    // Contoh dari Livewire:
    //   $this->dispatch('swal-confirm', [
    //       'title'   => 'Tutup Shift?',
    //       'text'    => 'Shift aktif akan ditutup.',
    //       'method'  => 'closeShift',
    //       'args'    => [],
    //   ]);
    document.addEventListener('livewire:init', () => {
        Livewire.on('swal-confirm', (payload) => {
            const { title, text, method, args = [], componentId } = Array.isArray(payload) ? payload[0] : payload;

            Swal.fire({
                title: title || 'Konfirmasi',
                text:  text  || '',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#BE123C',
                cancelButtonColor: '#6B7280',
                confirmButtonText: 'Ya, Lanjutkan',
                cancelButtonText: 'Batal',
                reverseButtons: true,
                customClass: { popup: 'rounded-2xl' },
            }).then((result) => {
                if (!result.isConfirmed) return;
                if (componentId) {
                    const component = Livewire.find(componentId);
                    if (component) component.$wire.call(method, ...args);
                } else {
                    // Broadcast ke semua — gunakan hanya jika method unik
                    Livewire.all().forEach(c => {
                        if (typeof c.$wire[method] === 'function') {
                            c.$wire.call(method, ...args);
                        }
                    });
                }
            });
        });
    });
</script>
